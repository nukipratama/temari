<?php

declare(strict_types=1);

use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

it('sends a message to the bot API for the given chat', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    (new TelegramClient())->sendMessage(99887766, 'Halo!');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
        && $request['chat_id'] === 99887766
        && $request['text'] === 'Halo!');
});

it('registers the webhook url and secret token', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    (new TelegramClient())->setWebhook('https://example.test/telegram/webhook', 'shh-secret');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/setWebhook'
        && $request['url'] === 'https://example.test/telegram/webhook'
        && $request['secret_token'] === 'shh-secret');
});

it('returns the queued updates array from getUpdates', function (): void {
    $updates = [
        ['update_id' => 10, 'message' => ['text' => '/start abc', 'chat' => ['id' => 1]]],
        ['update_id' => 11, 'message' => ['text' => '/stop', 'chat' => ['id' => 1]]],
    ];

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => $updates]),
    ]);

    $result = (new TelegramClient())->getUpdates(offset: 10, timeout: 30);

    expect($result)->toBe($updates);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/getUpdates'
        && $request['offset'] === 10
        && $request['timeout'] === 30);
});

it('throws a TelegramApiException carrying the status on an HTTP error', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
    ]);

    expect(fn () => (new TelegramClient())->sendMessage(1, 'hi'))
        ->toThrow(TelegramApiException::class, 'Bad Request: chat not found');
});

it('throws when Telegram answers 200 but ok is false', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 200),
    ]);

    try {
        (new TelegramClient())->sendMessage(1, 'hi');
        $this->fail('Expected TelegramApiException was not thrown.');
    } catch (TelegramApiException $e) {
        expect($e->status)->toBe(200)
            ->and($e->getMessage())->toContain('Unauthorized');
    }
});

it('wraps a transport failure in a TelegramApiException with no status', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    try {
        (new TelegramClient())->sendMessage(1, 'hi');
        $this->fail('Expected TelegramApiException was not thrown.');
    } catch (TelegramApiException $e) {
        expect($e->status)->toBeNull()
            ->and($e->getMessage())->toContain('could not reach the API');
    }
});
