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

    new TelegramClient()->sendMessage(99887766, 'Halo!');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
        && $request['chat_id'] === 99887766
        && $request['text'] === 'Halo!');
});

it('registers the webhook url and secret token', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    new TelegramClient()->setWebhook('https://example.test/telegram/webhook', 'shh-secret');

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

    $result = new TelegramClient()->getUpdates(offset: 10, timeout: 30);

    expect($result)->toBe($updates);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/getUpdates'
        && $request['offset'] === 10
        && $request['timeout'] === 30);
});

it('throws a TelegramApiException carrying the status on an HTTP error', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
    ]);

    expect(fn () => new TelegramClient()->sendMessage(1, 'hi'))
        ->toThrow(TelegramApiException::class, 'Bad Request: chat not found');
});

it('throws when Telegram answers 200 but ok is false', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 200),
    ]);

    try {
        new TelegramClient()->sendMessage(1, 'hi');
        $this->fail('Expected TelegramApiException was not thrown.');
    } catch (TelegramApiException $e) {
        expect($e->status)->toBe(200)
            ->and($e->description)->toBe('Unauthorized')
            ->and($e->getMessage())->toContain('Unauthorized');
    }
});

// Guzzle redacts user:pass@host but not the path, and the bot token lives in
// the path — so an unredacted transport error hands the live token to every
// caller that logs or persists the reason.
it('keeps the bot token out of a transport failure message', function (): void {
    $token = (string) config('services.telegram.bot_token');
    expect($token)->not->toBe('');

    Http::fake(fn () => throw new ConnectionException(
        "cURL error 28: Operation timed out for https://api.telegram.org/bot{$token}/sendMessage",
    ));

    try {
        new TelegramClient()->sendMessage(1, 'hi');
        $this->fail('Expected TelegramApiException was not thrown.');
    } catch (TelegramApiException $e) {
        expect($e->getMessage())->not->toContain($token)
            ->and($e->getMessage())->toContain('[redacted]');
    }
});

it('wraps a transport failure in a TelegramApiException with no status', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    try {
        new TelegramClient()->sendMessage(1, 'hi');
        $this->fail('Expected TelegramApiException was not thrown.');
    } catch (TelegramApiException $e) {
        expect($e->status)->toBeNull()
            ->and($e->connectionFailed)->toBeTrue()
            ->and($e->getMessage())->toContain('could not reach the API');
    }
});

// #986: an explicit connect timeout bounds DNS/TCP setup independently of the
// total timeout, so a resolver/socket stall can't outlast either budget.
it('sets an explicit connect timeout alongside the total timeout', function (): void {
    $method = new ReflectionMethod(TelegramClient::class, 'client');

    $pending = $method->invoke(new TelegramClient(), 'test-bot-token', 0);

    expect($pending->getOptions())
        ->toHaveKey('connect_timeout', 5)
        ->toHaveKey('timeout', 10);
});

it('adds a long-poll timeout on top of the base total timeout, unaffected by the connect timeout', function (): void {
    $method = new ReflectionMethod(TelegramClient::class, 'client');

    $pending = $method->invoke(new TelegramClient(), 'test-bot-token', 30);

    expect($pending->getOptions())
        ->toHaveKey('connect_timeout', 5)
        ->toHaveKey('timeout', 40);
});

it('replaces the total timeout when a caller sets its own', function (): void {
    $method = new ReflectionMethod(TelegramClient::class, 'client');

    $pending = $method->invoke(new TelegramClient(), 'test-bot-token', 0, 5);

    expect($pending->getOptions())
        ->toHaveKey('connect_timeout', 5)
        ->toHaveKey('timeout', 5);
});
