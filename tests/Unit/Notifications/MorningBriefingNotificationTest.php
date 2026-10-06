<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

function briefingFor(User $user): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-24',
        'status' => AnalysisStatus::Done,
        'content' => '  easy 5k, nothing clever.  ',
    ]);
}

function subscribedUser(): User
{
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    return $user;
}

it('routes to web push alone when Telegram is not connected', function (): void {
    $user = subscribedUser();

    expect(new MorningBriefingNotification(briefingFor($user))->via($user))
        ->toBe([IdempotentWebPushChannel::class]);
});

// Deliberately not the inbox: the briefing is already on the dashboard, so a
// second record of it there would be noise. Telegram is an interruption timed
// to the moment, which is exactly what this notification is for.
it('reaches Telegram as well as push, never the inbox', function (): void {
    $user = subscribedUser();
    TelegramConnection::factory()->for($user)->create();

    expect(new MorningBriefingNotification(briefingFor($user))->via($user->fresh()))
        ->toBe([TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('reaches a Telegram-only athlete', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(new MorningBriefingNotification(briefingFor($user))->via($user->fresh()))
        ->toBe([TelegramChannel::class]);
});

it('sends nothing to an athlete with no channel wired', function (): void {
    $user = User::factory()->create();

    expect(new MorningBriefingNotification(briefingFor($user))->via($user))->toBe([]);
});

it('sends nothing once the master switch is off', function (): void {
    $user = subscribedUser();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new MorningBriefingNotification(briefingFor($user))->via($user))->toBe([]);
});

it('rechecks the master switch before a queued channel sends', function (): void {
    $user = subscribedUser();
    $notification = new MorningBriefingNotification(briefingFor($user));
    expect($notification->via($user))->toContain(IdempotentWebPushChannel::class);

    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect($notification->shouldSend($user, IdempotentWebPushChannel::class))->toBeFalse();
});

it('sends nothing to the demo identity', function (): void {
    $user = subscribedUser();
    TelegramConnection::factory()->for($user)->create();
    $user->update(['is_demo' => true]);

    expect(new MorningBriefingNotification(briefingFor($user))->via($user->fresh()))->toBe([]);
});

it('carries the briefing content to Telegram, keyed on the same briefing row', function (): void {
    $user = subscribedUser();
    $briefing = briefingFor($user);

    $message = new MorningBriefingNotification($briefing)->toTelegram($user);

    expect($message->text)->toContain('easy 5k, nothing clever.')
        ->and($message->text)->toContain(route('dashboard'))
        ->and($message->deliveryKey)->toBe($briefing->id);
});

it('carries the briefing content, trimmed, and opens the dashboard', function (): void {
    $user = subscribedUser();
    $notification = new MorningBriefingNotification(briefingFor($user));

    $message = $notification->toWebPush($user, $notification)->toArray();

    expect($message['title'])->toBe('your briefing for today')
        ->and($message['body'])->toBe('easy 5k, nothing clever.')
        ->and($message['data']['url'])->toBe(route('dashboard'));
});

// The briefing row is already one per athlete per day, so keying the shared
// delivery claim on it makes the push one per athlete per day too.
it('keys idempotency on the briefing row, so a second attempt is a no-op', function (): void {
    $user = subscribedUser();
    $briefing = briefingFor($user);
    $claim = new NotificationDeliveryClaim();

    expect(new MorningBriefingNotification($briefing)->deliveryKey())->toBe($briefing->id)
        ->and($claim->claim($briefing->id, 'webpush'))->toBe(1)
        ->and($claim->claim($briefing->id, 'webpush'))->toBeNull();
});

afterEach(fn () => Carbon::setTestNow());

it('sends a briefing delivered inside two hours of its bucket', function (string $at): void {
    Carbon::setTestNow($at);
    $user = subscribedUser();

    expect(new MorningBriefingNotification(briefingFor($user))->shouldSend($user, IdempotentWebPushChannel::class))->toBeTrue();
})->with([
    'on time' => '2026-05-24 06:05:00',
    'exactly two hours late' => '2026-05-24 08:00:00',
]);

it('skips a briefing delivered more than two hours past its bucket', function (): void {
    Carbon::setTestNow('2026-05-24 08:00:01');
    $user = subscribedUser();

    expect(new MorningBriefingNotification(briefingFor($user))->shouldSend($user, IdempotentWebPushChannel::class))->toBeFalse();
});

it('skips a briefing delivered on the next local day', function (): void {
    Carbon::setTestNow('2026-05-25 00:10:00');
    $user = subscribedUser();

    expect(new MorningBriefingNotification(briefingFor($user))->shouldSend($user, IdempotentWebPushChannel::class))->toBeFalse();
});

it('keeps the inbox channel regardless of staleness', function (): void {
    Carbon::setTestNow('2026-05-26 06:00:00');
    $user = subscribedUser();

    expect(new MorningBriefingNotification(briefingFor($user))->shouldSend($user, InAppChannel::class))->toBeTrue();
});

it('judges a replayed notification as of the moment it was held', function (): void {
    Carbon::setTestNow('2026-05-24 04:00:00');
    $user = subscribedUser();
    $notification = new MorningBriefingNotification(briefingFor($user));

    expect($notification->shouldSend($user, IdempotentWebPushChannel::class))->toBeTrue();

    Carbon::setTestNow('2026-05-24 09:00:00');
    expect($notification->shouldSend($user, IdempotentWebPushChannel::class))->toBeFalse();

    $notification->heldAt = Carbon::parse('2026-05-24 06:30:00');
    expect($notification->shouldSend($user, IdempotentWebPushChannel::class))->toBeTrue();
});

it('retries until two hours past its bucket, or the end of its day when that comes first', function (): void {
    Carbon::setTestNow('2026-05-24 06:05:00');
    $user = subscribedUser();
    $briefing = briefingFor($user);

    expect(new MorningBriefingNotification($briefing)->retryUntil()->toDateTimeString())->toBe('2026-05-24 08:00:00');

    $lateRunner = subscribedUser();
    foreach (range(1, 5) as $day) {
        $activity = Activity::factory()->for($lateRunner)->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => "2026-05-0{$day} 23:30:00"]);
    }

    expect(new MorningBriefingNotification(briefingFor($lateRunner))->retryUntil()->toDateTimeString())->toBe('2026-05-24 23:59:59');
});

it('logs a stale skip with its type and settles the delivery row as failed', function (): void {
    Carbon::setTestNow('2026-05-24 09:00:00');
    Log::spy();
    $user = subscribedUser();
    $briefing = briefingFor($user);

    expect(new MorningBriefingNotification($briefing)->shouldSend($user, IdempotentWebPushChannel::class))->toBeFalse();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'notifications.stale_skipped'
        && $context['type'] === MorningBriefingNotification::class)->once();
    $row = NotificationDelivery::query()->where('analysis_id', $briefing->id)->where('channel', 'webpush')->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($row->error)->toContain('stale');
});
