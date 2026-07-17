<?php

declare(strict_types=1);

use App\Jobs\Telegram\HandleTelegramUpdateJob;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
});

function runUpdate(array $update): void
{
    new HandleTelegramUpdateJob($update)->handle(
        app(TelegramClient::class),
        app(TelegramLinkToken::class),
    );
}

function startUpdate(int $chatId, string $token, ?string $username = null): array
{
    return [
        'message' => [
            'chat' => ['id' => $chatId],
            'from' => ['username' => $username],
            'text' => '/start ' . $token,
        ],
    ];
}

it('links the chat and replies with a welcome naming the account on a valid token', function (): void {
    $user = User::factory()->create(['name' => 'Budi Lari']);
    $token = app(TelegramLinkToken::class)->mint($user->id);

    runUpdate(startUpdate(555, $token, 'budi_runs'));

    $this->assertDatabaseHas('telegram_connections', [
        'user_id' => $user->id,
        'chat_id' => 555,
        'username' => 'budi_runs',
        'revoked_at' => null,
    ]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Budi Lari')
        && $request['chat_id'] === 555);
});

it('does not link and replies with the expired copy on an expired token', function (): void {
    // The expiry check fails before the job ever reads the user row, so mint()
    // only needs a plausible id, not a persisted User.
    $expired = $this->travelTo(now()->subHours(2), fn (): string => app(TelegramLinkToken::class)->mint(999));

    runUpdate(startUpdate(555, $expired));

    $this->assertDatabaseMissing('telegram_connections', ['chat_id' => 555]);
    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'gak berlaku'));
});

it('treats a token as single-use: a second /start with the same token does not re-link', function (): void {
    $user = User::factory()->create(['name' => 'Budi Lari']);
    $token = app(TelegramLinkToken::class)->mint($user->id);

    // First /start links from chat 555.
    runUpdate(startUpdate(555, $token));
    $this->assertDatabaseHas('telegram_connections', ['user_id' => $user->id, 'chat_id' => 555]);

    // Replaying the same token from a different chat must not re-bind the account.
    runUpdate(startUpdate(999, $token));

    expect($user->telegramConnection->fresh()->chat_id)->toBe(555);
    Http::assertSent(fn ($request): bool => $request['chat_id'] === 999
        && str_contains((string) $request['text'], 'gak berlaku'));
});

it('does not link and replies generically on a garbage token', function (): void {
    runUpdate(startUpdate(555, 'garbage-token'));

    $this->assertDatabaseMissing('telegram_connections', ['chat_id' => 555]);
    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Hubungkan Telegram'));
});

it('revokes the connection and confirms on /stop', function (): void {
    $connection = TelegramConnection::factory()->create(['chat_id' => 777]);

    runUpdate(['message' => ['chat' => ['id' => 777], 'text' => '/stop']]);

    expect($connection->fresh()->isRevoked())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'aku lepas'));
});

it('replies generically to any other message', function (): void {
    runUpdate(['message' => ['chat' => ['id' => 777], 'text' => 'halo bot']]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Hubungkan Telegram'));
});

it('ignores an update with no message', function (): void {
    runUpdate(['edited_channel_post' => []]);

    Http::assertNothingSent();
});

it('ignores a message with a missing or non-int chat id', function (?array $chat): void {
    runUpdate(['message' => ['chat' => $chat, 'text' => 'halo bot']]);

    Http::assertNothingSent();
})->with([
    'missing chat entirely' => [null],
    'non-numeric id' => [['id' => 'not-an-id']],
]);

it('clears a revoked connection from another user before re-linking the same chat_id', function (): void {
    // A Telegram account previously linked to user A, then /stop-ped, now tries
    // to link to user B via the same chat_id. Without clearing the stale
    // revoked row first, the unique constraint on chat_id would throw.
    $userA = User::factory()->create();
    TelegramConnection::factory()->for($userA)->revoked()->create(['chat_id' => 555]);

    $userB = User::factory()->create(['name' => 'Budi Lari']);
    $token = app(TelegramLinkToken::class)->mint($userB->id);

    runUpdate(startUpdate(555, $token, 'budi_runs'));

    expect(TelegramConnection::query()->where('chat_id', 555)->where('user_id', $userA->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('telegram_connections', [
        'user_id' => $userB->id,
        'chat_id' => 555,
        'revoked_at' => null,
    ]);
});
