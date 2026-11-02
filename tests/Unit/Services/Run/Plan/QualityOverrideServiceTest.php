<?php

declare(strict_types=1);

use App\Enums\PlanOverrideKind;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\DayClampedNotification;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\QualityOverrideService;
use App\Services\Run\Plan\RestClampRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{user: User, session: PlannedSession, week: PlanAdaptation} */
function easedIntervalToday(): array
{
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = PlannedSession::factory()->for($user)->create(['date' => '2026-08-12', 'session_type' => SessionType::Interval]);
    $week = PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-08-10']);
    app(RestClampRecorder::class)->record($user, Carbon::today());

    return ['user' => $user, 'session' => $session->refresh(), 'week' => $week];
}

function overrideRefusal(Closure $attempt): ?string
{
    try {
        $attempt();
    } catch (ValidationException $e) {
        return $e->validator->errors()->first('override');
    }

    return null;
}

function overrideService(): QualityOverrideService
{
    return app(QualityOverrideService::class);
}

it('runs today\'s eased interval as written once the athlete is ready, clearing the recorded ease', function (): void {
    ['user' => $user, 'session' => $session, 'week' => $week] = easedIntervalToday();
    expect($session->clamped_km)->not->toBeNull();

    expect(overrideService()->take($user, $session, Carbon::today()))->toBe(PlanOverrideKind::ReadinessEase);

    $week->refresh();
    $session->refresh();
    expect($week->override_kind)->toBe(PlanOverrideKind::ReadinessEase)
        ->and($week->override_date?->toDateString())->toBe('2026-08-12')
        ->and($week->overridden_at)->not->toBeNull()
        ->and($session->clamped_km)->toBeNull()
        ->and($session->readiness_assessment)->toBeNull()
        ->and($session->session_type)->toBe(SessionType::Interval);
});

it('allows one override a week', function (): void {
    ['user' => $user, 'session' => $session] = easedIntervalToday();
    overrideService()->take($user, $session, Carbon::today());

    expect(overrideRefusal(fn () => overrideService()->take($user, $session->refresh(), Carbon::today())))->not->toBeNull();
});

it('refuses a day the plan offers nothing to override on', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create(['date' => '2026-08-12', 'session_type' => SessionType::Easy]);
    PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-08-10']);

    expect(overrideRefusal(fn () => overrideService()->take($user, $session, Carbon::today())))->not->toBeNull()
        ->and(PlanAdaptation::query()->firstOrFail()->override_kind)->toBeNull();
});

it('eases the day again quietly and refunds the week\'s override', function (): void {
    ['user' => $user, 'session' => $session, 'week' => $week] = easedIntervalToday();
    Notification::fake();
    overrideService()->take($user, $session, Carbon::today());

    overrideService()->undo($user, $session->refresh(), Carbon::today());

    $week->refresh();
    expect($week->override_kind)->toBeNull()
        ->and($week->override_date)->toBeNull()
        ->and($week->overridden_at)->toBeNull()
        ->and($session->refresh()->clamped_km)->not->toBeNull();
    Notification::assertNotSentTo($user, DayClampedNotification::class);
});

it('keeps the override once a run lands on the day', function (): void {
    ['user' => $user, 'session' => $session, 'week' => $week] = easedIntervalToday();
    overrideService()->take($user, $session, Carbon::today());
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create(['start_date_local' => '2026-08-12 07:00:00', 'distance' => 8_000]);

    expect(overrideRefusal(fn () => overrideService()->undo($user, $session->refresh(), Carbon::today())))->not->toBeNull()
        ->and($week->refresh()->override_kind)->toBe(PlanOverrideKind::ReadinessEase);
});

it('brings back the session a ran-too-hard week dropped on its natural day, and drops it again on undo', function (): void {
    $user = User::factory()->create();
    seedRanTooHardWeek($user);
    app(Periodizer::class)->regenerate($user);
    $thursday = static fn (): PlannedSession => PlannedSession::query()->where('user_id', $user->id)->where('date', '2026-09-17')->firstOrFail();
    expect($thursday()->session_type)->toBe(SessionType::Easy);

    expect(overrideService()->take($user, $thursday(), Carbon::today()))->toBe(PlanOverrideKind::WeeklyDrop)
        ->and($thursday()->session_type)->toBe(SessionType::Interval)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->override_date?->toDateString())->toBe('2026-09-17');

    overrideService()->undo($user, $thursday(), Carbon::today());

    expect($thursday()->session_type)->toBe(SessionType::Easy)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->override_kind)->toBeNull();
});

it('refuses to undo on a day the athlete never overrode', function (): void {
    ['user' => $user, 'session' => $session] = easedIntervalToday();

    expect(overrideRefusal(fn () => overrideService()->undo($user, $session, Carbon::today())))->not->toBeNull();
});

it('checks the offer inside the plan lock, so a move that lands while it waits wins', function (): void {
    ['user' => $user, 'session' => $session, 'week' => $week] = easedIntervalToday();
    $move = Cache::lock("plan-reconciliation:{$user->id}", 10);
    expect($move->get())->toBeTrue();
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($session, $move): void {
        $session->update(['pinned' => true]);
        $move->release();
    });

    expect(overrideRefusal(fn () => overrideService()->take($user, $session, Carbon::today())))->not->toBeNull()
        ->and($week->refresh()->override_kind)->toBeNull();
});
