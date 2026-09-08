<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\StravaDisconnectedNotification;
use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    $this->revokedAt = Carbon::parse('2026-09-08 06:30:00');
});

it('reaches every wired channel, since a dead sync is worth interrupting', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    expect(new StravaDisconnectedNotification($this->revokedAt)->via($user->fresh()))
        ->toBe([InAppChannel::class, TelegramChannel::class, IdempotentWebPushChannel::class]);
});

// The master switch names what it covers in its own Settings description — the
// post-run story, the recaps and the streak nudge — and this is none of them: it
// is the app reporting that something the athlete wired up has stopped working.
it('is not governed by the notifications master switch', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new StravaDisconnectedNotification($this->revokedAt)->via($user->fresh()))
        ->toBe([InAppChannel::class, TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('still honours the per-channel mutes, which answer where rather than whether', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create([
        'telegram_enabled' => false,
        'push_enabled' => false,
    ]);

    expect(new StravaDisconnectedNotification($this->revokedAt)->via($user->fresh()))
        ->toBe([InAppChannel::class]);
});

it('routes the demo user to the inbox alone', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);

    expect(new StravaDisconnectedNotification($this->revokedAt)->via($user->fresh()))
        ->toBe([InAppChannel::class]);
});

it('records an inbox row pointing at the page that carries the reconnect button', function (): void {
    $message = new StravaDisconnectedNotification($this->revokedAt)->toInbox(User::factory()->create());

    expect($message->kind)->toBe(NotificationKind::StravaDisconnected)
        ->and($message->title)->toBe('Strava stopped syncing')
        ->and($message->body)->toBe(
            'your Strava connection dropped, so no new runs are coming in until you reconnect it from your profile.',
        )
        ->and($message->payload)->toBe(['url' => route('profile')]);
});

// Keyed on the instant of the revocation rather than the connection, so a
// queued retry writes one row while a later reconnect-then-revoke writes its own.
it('dedupes on the revocation instant', function (): void {
    $message = new StravaDisconnectedNotification($this->revokedAt)->toInbox(User::factory()->create());

    expect($message->dedupeKey)->toBe('strava_disconnected:'.$this->revokedAt->timestamp);
});

it('carries the reconnect link into the Telegram body', function (): void {
    $message = new StravaDisconnectedNotification($this->revokedAt)->toTelegram(User::factory()->create());

    expect($message->text)->toContain('Strava stopped syncing')
        ->and($message->text)->toContain(route('profile'))
        ->and($message->deliveryKey)->toBeNull();
});

it('builds a web push carrying the same reconnect link', function (): void {
    $notification = new StravaDisconnectedNotification($this->revokedAt);

    $message = $notification->toWebPush(User::factory()->create(), $notification);
    $payload = $message->toArray();

    expect($payload['title'])->toBe('Strava stopped syncing')
        ->and($payload['data'])->toBe(['url' => route('profile')]);
});
