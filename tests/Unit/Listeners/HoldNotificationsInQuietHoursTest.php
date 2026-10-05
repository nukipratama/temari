<?php

declare(strict_types=1);

use App\Listeners\HoldNotificationsInQuietHours;
use App\Models\HeldNotification;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\StreakReminderNotification;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['notifications.hold_during_quiet_hours' => true]));

afterEach(fn () => Carbon::setTestNow());

function sendingEvent(mixed $notifiable, Notification $notification): NotificationSending
{
    $notification->id = 'f3b1c2d4-0000-4000-8000-000000000001';

    return new NotificationSending($notifiable, $notification, InAppChannel::class);
}

it('holds a send inside the window and keeps the notification id', function (): void {
    Carbon::setTestNow('2026-10-05 23:00:00');
    $user = User::factory()->create();

    $result = new HoldNotificationsInQuietHours()->handle(sendingEvent($user, new StreakReminderNotification(4)));

    $held = HeldNotification::query()->sole();
    expect($result)->toBeFalse()
        ->and($held->user_id)->toBe($user->id)
        ->and($held->channel)->toBe(InAppChannel::class)
        ->and($held->held_at->toDateTimeString())->toBe('2026-10-05 23:00:00')
        ->and(unserialize($held->notification)->id)->toBe('f3b1c2d4-0000-4000-8000-000000000001');
});

it('lets a send outside the window through', function (): void {
    Carbon::setTestNow('2026-10-05 21:59:59');

    $result = new HoldNotificationsInQuietHours()->handle(sendingEvent(User::factory()->create(), new StreakReminderNotification(4)));

    expect($result)->toBeNull()
        ->and(HeldNotification::query()->count())->toBe(0);
});

it('never holds the manual test notification', function (): void {
    Carbon::setTestNow('2026-10-05 23:00:00');

    $result = new HoldNotificationsInQuietHours()->handle(sendingEvent(User::factory()->create(), new TestNotification()));

    expect($result)->toBeNull()
        ->and(HeldNotification::query()->count())->toBe(0);
});

it('leaves a send to anyone but an athlete alone', function (): void {
    Carbon::setTestNow('2026-10-05 23:00:00');

    $result = new HoldNotificationsInQuietHours()->handle(sendingEvent(new AnonymousNotifiable(), new StreakReminderNotification(4)));

    expect($result)->toBeNull()
        ->and(HeldNotification::query()->count())->toBe(0);
});
