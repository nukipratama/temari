<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->and($claim->claim($briefing->id, 'webpush'))->toBeTrue()
        ->and($claim->claim($briefing->id, 'webpush'))->toBeFalse();
});
