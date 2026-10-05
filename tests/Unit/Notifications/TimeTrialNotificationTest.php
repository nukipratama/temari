<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Enums\SessionType;
use App\Models\NotificationPreference;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\TimeTrialNotification;
use App\Services\Run\Plan\TimeTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushMessage;

uses(RefreshDatabase::class);

function askedTrial(User $user, int $distanceM = 5_000): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => '2026-10-06',
        'session_type' => SessionType::Interval,
        'prescription_race_context' => new TimeTrial($distanceM, 1_500)->context(),
    ]);
}

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new TimeTrialNotification(askedTrial($user))->via($user))
        ->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing once the master switch is off', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new TimeTrialNotification(askedTrial($user))->via($user))->toBe([]);
});

it('asks by weekday and distance whether the run was the all-out trial, pointing home', function (int $distanceM, string $title): void {
    $user = User::factory()->create();
    $session = askedTrial($user, $distanceM);

    $message = new TimeTrialNotification($session)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::TimeTrial)
        ->and($message->title)->toBe($title)
        ->and($message->body)->toContain('nothing changes')
        ->and($message->payload['url'])->toBe(route('dashboard'))
        ->and($message->dedupeKey)->toBe('time_trial:'.$session->id.':2026-10-06');
})->with([
    '5K' => [5_000, "was Tuesday's 5K your all-out trial?"],
    '10K' => [10_000, "was Tuesday's 10K your all-out trial?"],
]);

it('carries the same copy on telegram and web push', function (): void {
    $user = User::factory()->create();
    $notification = new TimeTrialNotification(askedTrial($user));

    expect($notification->toTelegram($user)->text)->toContain("was Tuesday's 5K your all-out trial?")->toContain(route('dashboard'))
        ->and($notification->toWebPush($user, $notification))->toBeInstanceOf(WebPushMessage::class);
});
