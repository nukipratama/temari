<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\InboxNotification;
use App\Models\NotificationPreference;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\PlanChangedNotification;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanChangeNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const CHANGED_DAY = '2026-11-05';

const CHANGED_WAS = ['session_type' => 'tempo', 'hard_minutes' => 20, 'pace_band' => 'threshold'];

beforeEach(fn () => Carbon::setTestNow('2026-11-03 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @param array<string, mixed> $attributes */
function changedSession(User $user, array $attributes): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => CHANGED_DAY,
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
        ...$attributes,
    ]);
}

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS)->via($user))
        ->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing once the master switch is off, or to the demo athlete', function (): void {
    $muted = User::factory()->create();
    NotificationPreference::factory()->for($muted)->create(['notifications_enabled' => false]);

    expect(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS)->via($muted))->toBe([])
        ->and(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS)->via(User::factory()->demo()->create()))->toBe([]);
});

it('says what changed and why, in sentence case', function (array $now, string $title, string $body): void {
    $user = User::factory()->create();
    changedSession($user, $now);

    $message = new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::PlanChange)
        ->and($message->title)->toBe($title)
        ->and($message->body)->toBe($body)
        ->and($message->payload['url'])->toBe(route('plan'))
        ->and($message->dedupeKey)->toBe('plan_change:'.CHANGED_DAY);
})->with([
    'budget trim with a bring-back cost' => [
        ['prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null, 'prescription_reason' => Periodizer::BUDGET_SPENT_REASON, 'prescription_budget' => ['cap_minutes' => 60, 'left_minutes' => 4, 'needed_minutes' => 25]],
        "Thursday's tempo is now an easy run",
        "This week's hard-minute budget is spent. Bring it back from the plan for 21 hard min over this week's cap.",
    ],
    'fewer hard minutes' => [
        ['prescribed_hard_minutes' => 12, 'prescription_reason' => 'bounded by the distance this day holds'],
        "Thursday's tempo is now 12 hard minutes",
        'Bounded by the distance this day holds.',
    ],
    'a new session type' => [
        ['session_type' => SessionType::Interval, 'prescribed_pace_band' => PaceBand::Interval],
        "Thursday's tempo is now an interval session",
        'Open the plan to see the week as it stands.',
    ],
    'a new pace band' => [
        ['prescribed_pace_band' => PaceBand::Marathon],
        "Thursday's tempo is now at marathon pace",
        'Open the plan to see the week as it stands.',
    ],
]);

it('says a long run lost its fast finish', function (): void {
    $user = User::factory()->create();
    changedSession($user, ['session_type' => SessionType::Long, 'prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null, 'prescription_reason' => Periodizer::NO_FAST_FINISH_REASON]);

    $message = new PlanChangedNotification(CHANGED_DAY, ['session_type' => 'long', 'hard_minutes' => 15, 'pace_band' => 'marathon'])->toInbox($user);

    expect($message->title)->toBe("Thursday's long run no longer has a fast finish")
        ->and($message->body)->toBe("This week's hard-minute budget is spent.");
});

it('skips a change that reverted before sending, and frees the session for a later change', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-11-02']);
    $session = changedSession($user, []);
    $notice = app(PlanChangeNotice::class);

    $baseline = $notice->baseline($user, Carbon::today());
    $session->update(['prescribed_hard_minutes' => 0]);
    $notice->announce($user, $baseline, Carbon::today());
    $session->update(['prescribed_hard_minutes' => 20]);

    expect(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS)->shouldSend($user, InAppChannel::class))->toBeFalse();

    $baseline = $notice->baseline($user, Carbon::today());
    $session->update(['prescribed_hard_minutes' => 10]);
    $notice->announce($user, $baseline, Carbon::today());

    Notification::assertSentToTimes($user, PlanChangedNotification::class, 2);
});

it('skips a session the athlete has since run or pinned', function (array $attributes): void {
    $user = User::factory()->create();
    changedSession($user, ['prescribed_hard_minutes' => 0, ...$attributes]);

    expect(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS)->shouldSend($user, InAppChannel::class))->toBeFalse();
})->with([
    'pinned' => [['pinned' => true]],
    'run' => [['status' => 'done']],
]);

it('writes one inbox row however many times it is delivered', function (): void {
    $user = User::factory()->create();
    changedSession($user, ['prescribed_hard_minutes' => 0]);

    $user->notifyNow(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS));
    $user->notifyNow(new PlanChangedNotification(CHANGED_DAY, CHANGED_WAS));

    expect(InboxNotification::query()->where('user_id', $user->id)->where('dedupe_key', 'plan_change:'.CHANGED_DAY)->count())->toBe(1);
});
