<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\StreakReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Telegram routing now requires a configured bot token, the same precondition
// AnalysisReadyNotification always enforced. Unifying the six reachability
// checks into ChannelRouter applied it everywhere, so these tests have to
// satisfy it rather than route to a channel that could not actually send.
beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

function streakVia(User $user): array
{
    return new StreakReminderNotification(3)->via($user);
}

function subscribeToPush(User $user): void
{
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
}

it('routes to Telegram for a connected, opted-in user', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(streakVia($user))->toBe([InAppChannel::class, TelegramChannel::class]);
});

// The gap this closes: a push-only user used to get recaps but never streak
// nudges, because via() was hardcoded to Telegram.
it('routes to web push for a subscribed user with no Telegram connection', function (): void {
    $user = User::factory()->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('routes to both channels when both are wired', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([InAppChannel::class, TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('routes nowhere for the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([]);
});

it('routes to the inbox alone with no outbound channel wired', function (): void {
    expect(streakVia(User::factory()->create()))->toBe([InAppChannel::class]);
});

it('routes to web push only over a revoked Telegram connection', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('routes to the inbox alone over a revoked connection with no push subscription', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    expect(streakVia($user))->toBe([InAppChannel::class]);
});

// The nudge is governed by the master switch, which names it in its own
// description, rather than riding along on a recap toggle that never mentioned it.
it('routes nowhere when the notification master switch is off, on either channel', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    subscribeToPush($user);
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(streakVia($user))->toBe([]);
});

it('still routes when the master switch is on', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => true]);

    expect(streakVia($user))->toBe([InAppChannel::class, TelegramChannel::class]);
});

it('builds a keyless Telegram message naming the streak length', function (): void {
    $message = new StreakReminderNotification(3)->toTelegram(User::factory()->create());

    expect($message->text)->toContain('3-week')
        ->and($message->text)->toContain('Open Temari')
        ->and($message->deliveryKey)->toBeNull();
});

it('builds a web push carrying the same streak length and a tap-through url', function (): void {
    $notification = new StreakReminderNotification(3);
    $message = $notification->toWebPush(User::factory()->create(), $notification);
    $payload = $message->toArray();

    expect($payload['title'])->toContain('3-week')
        ->and($payload['body'])->toContain("streak doesn't break")
        ->and($payload['data'])->toBe(['url' => route('dashboard')])
        ->and($message->getOptions())->toBe(['urgency' => 'high']);
});
