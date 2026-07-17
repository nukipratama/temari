<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendTelegramTestJob;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['services.telegram.bot_token' => 'test-bot-token']));

it('sends the test message to an active connection', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242]);

    new SendTelegramTestJob($user->id)->handle(app(TelegramClient::class));

    Http::assertSent(fn ($request): bool => $request['chat_id'] === 4242
        && str_contains((string) $request['text'], 'Tes notifikasi dari Temari'));
});

it('no-ops when the connection is missing or revoked', function (): void {
    Http::fake();

    $noConnection = User::factory()->create();
    new SendTelegramTestJob($noConnection->id)->handle(app(TelegramClient::class));

    $revoked = User::factory()->create();
    TelegramConnection::factory()->for($revoked)->revoked()->create();
    new SendTelegramTestJob($revoked->id)->handle(app(TelegramClient::class));

    Http::assertNothingSent();
});

it('revokes the connection and does not retry when the bot is blocked (403)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create();

    new SendTelegramTestJob($user->id)->handle(app(TelegramClient::class));

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('rethrows a retryable failure (5xx) so the queue retry still applies', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create();

    expect(fn () => new SendTelegramTestJob($user->id)->handle(app(TelegramClient::class)))
        ->toThrow(TelegramApiException::class);

    expect($connection->fresh()->isRevoked())->toBeFalse();
});
