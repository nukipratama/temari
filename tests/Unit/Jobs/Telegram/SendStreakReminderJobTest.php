<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendStreakReminderJob;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['services.telegram.bot_token' => 'test-bot-token']));

it('sends the streak-at-risk message to an opted-in connection', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_weekly_recap' => true]);

    new SendStreakReminderJob($user->id, 3)->handle(app(TelegramClient::class));

    Http::assertSent(fn ($request): bool => $request['chat_id'] === 4242
        && str_contains((string) $request['text'], '3 minggu'));
});

it('no-ops for the demo user, a missing/revoked connection, or an opted-out one', function (): void {
    Http::fake();

    $demo = User::factory()->demo()->create();
    TelegramConnection::factory()->for($demo)->create(['notify_weekly_recap' => true]);
    new SendStreakReminderJob($demo->id, 3)->handle(app(TelegramClient::class));

    $noConnection = User::factory()->create();
    new SendStreakReminderJob($noConnection->id, 3)->handle(app(TelegramClient::class));

    $revoked = User::factory()->create();
    TelegramConnection::factory()->for($revoked)->revoked()->create(['notify_weekly_recap' => true]);
    new SendStreakReminderJob($revoked->id, 3)->handle(app(TelegramClient::class));

    $optedOut = User::factory()->create();
    TelegramConnection::factory()->for($optedOut)->create(['notify_weekly_recap' => false]);
    new SendStreakReminderJob($optedOut->id, 3)->handle(app(TelegramClient::class));

    Http::assertNothingSent();
});

it('revokes the connection and does not retry when the bot is blocked (403)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);

    new SendStreakReminderJob($user->id, 3)->handle(app(TelegramClient::class));

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('rethrows a retryable failure (5xx) so the queue retry still applies', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);

    expect(fn () => new SendStreakReminderJob($user->id, 3)->handle(app(TelegramClient::class)))
        ->toThrow(TelegramApiException::class);

    expect($connection->fresh()->isRevoked())->toBeFalse();
});
