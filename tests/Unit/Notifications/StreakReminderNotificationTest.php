<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\StreakReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function streakVia(User $user): array
{
    return new StreakReminderNotification(3)->via($user);
}

function subscribeToPush(User $user): void
{
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
}

it('routes to Telegram for a connected, weekly-opted-in user', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(streakVia($user))->toBe([TelegramChannel::class]);
});

// The gap this closes: a push-only user used to get recaps but never streak
// nudges, because via() was hardcoded to Telegram.
it('routes to web push for a subscribed user with no Telegram connection', function (): void {
    $user = User::factory()->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([IdempotentWebPushChannel::class]);
});

it('routes to both channels when both are wired', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('routes nowhere for the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([]);
});

it('routes nowhere with no channel wired', function (): void {
    expect(streakVia(User::factory()->create()))->toBe([]);
});

it('routes to web push only over a revoked Telegram connection', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();
    subscribeToPush($user);

    expect(streakVia($user))->toBe([IdempotentWebPushChannel::class]);
});

it('routes nowhere over a revoked connection with no push subscription', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    expect(streakVia($user))->toBe([]);
});

it('routes nowhere when weekly recap is opted out, on either channel', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    subscribeToPush($user);
    NotificationPreference::factory()->for($user)->create(['weekly_recap' => false]);

    expect(streakVia($user))->toBe([]);
});

it('builds a keyless Telegram message naming the streak length', function (): void {
    $message = new StreakReminderNotification(3)->toTelegram(User::factory()->create());

    expect($message->text)->toContain('3 minggu')
        ->and($message->text)->toContain('Buka Temari')
        ->and($message->deliveryKey)->toBeNull();
});

it('builds a web push carrying the same streak length and a tap-through url', function (): void {
    $notification = new StreakReminderNotification(3);
    $message = $notification->toWebPush(User::factory()->create(), $notification);
    $payload = $message->toArray();

    expect($payload['title'])->toContain('3 minggu')
        ->and($payload['body'])->toContain('streak-nya nggak putus')
        ->and($payload['data'])->toBe(['url' => route('dashboard')])
        ->and($message->getOptions())->toBe(['urgency' => 'high']);
});
