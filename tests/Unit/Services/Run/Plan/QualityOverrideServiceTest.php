<?php

declare(strict_types=1);

use App\Enums\PlanOverrideKind;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
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

it('refuses rather than failing once a rebuild that lands while it waits replaces the day', function (): void {
    ['user' => $user, 'session' => $session, 'week' => $week] = easedIntervalToday();
    $rebuild = Cache::lock("plan-reconciliation:{$user->id}", 10);
    expect($rebuild->get())->toBeTrue();
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($session, $rebuild): void {
        PlannedSession::query()->whereKey($session->id)->delete();
        $rebuild->release();
    });

    expect(overrideRefusal(fn () => overrideService()->take($user, $session, Carbon::today())))->not->toBeNull()
        ->and($week->refresh()->override_kind)->toBeNull();
});

/**
 * @return array{user: User, trimmed: PlannedSession, day: Closure(): PlannedSession, week: Closure(): PlanAdaptation}
 */
function budgetTrimmedWeek(): array
{
    Carbon::setTestNow('2026-08-10 06:00:00');
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->subWeeks($i)->toDateString(), 'runs' => 4, 'distance_km' => 30.0]);
    }
    foreach ([2, 4, 5, 7, 9, 11, 12, 14, 16, 18, 19, 21, 23, 25, 26, 28] as $daysAgo) {
        ActivityDetail::factory()->for(Activity::factory()->for($user))->create(['start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(6, 0), 'distance' => 7_500, 'moving_time' => 2_475]);
    }
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    seedConfirmedEffort($user, 10_000, 2700, Carbon::today());
    app(Periodizer::class)->regenerate($user, Carbon::today());
    PlanAdaptation::query()->where('user_id', $user->id)->update(['hard_cap_minutes' => 1]);
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $trimmed = PlannedSession::query()->where('user_id', $user->id)->whereNotNull('prescription_budget')->orderBy('date')->firstOrFail();

    return [
        'user' => $user,
        'trimmed' => $trimmed,
        'day' => static fn (): PlannedSession => PlannedSession::query()->where('user_id', $user->id)->whereDate('date', $trimmed->date)->firstOrFail(),
        'week' => static fn (): PlanAdaptation => PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail(),
    ];
}

it('brings back a session the hard-minute budget trimmed, over the cap, and trims it again on undo', function (): void {
    ['user' => $user, 'trimmed' => $trimmed, 'day' => $day] = budgetTrimmedWeek();

    expect(overrideService()->take($user, $trimmed, Carbon::today()))->toBe(PlanOverrideKind::BudgetDrop)
        ->and($day()->prescription_budget)->toBeNull()
        ->and($day()->prescribed_hard_minutes)->toBeGreaterThan(0);

    overrideService()->undo($user, $day(), Carbon::today());

    expect($day()->prescription_budget)->not->toBeNull()
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->override_kind)->toBeNull();
});

it('offers no bring-back on a day the uncapped plan would run easy anyway, because its full dose does not fit', function (): void {
    Carbon::setTestNow('2026-08-10 06:00:00');
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->subWeeks($i)->toDateString(), 'runs' => 4, 'distance_km' => 30.0]);
    }
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    seedConfirmedEffort($user, 10_000, 2700, Carbon::today());
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $uncapped = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-13')->firstOrFail();
    PlanAdaptation::query()->where('user_id', $user->id)->update(['hard_cap_minutes' => 1]);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect($uncapped->prescription_reason)->toBe('easy because the outing cannot safely fit the minimum quality structure')
        ->and(PlannedSession::query()->where('user_id', $user->id)->whereNotNull('prescription_budget')->exists())->toBeFalse();
});

it('hands a budget bring-back back once a rebuild no longer trims its day, and keeps it while the trim stands', function (): void {
    ['user' => $user, 'trimmed' => $trimmed, 'day' => $day, 'week' => $week] = budgetTrimmedWeek();
    overrideService()->take($user, $trimmed, Carbon::today());
    $fullDose = $day()->prescribed_hard_minutes;

    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect($week()->override_kind)->toBe(PlanOverrideKind::BudgetDrop);

    PlanAdaptation::query()->where('user_id', $user->id)->update(['hard_cap_minutes' => 500]);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect($week()->override_kind)->toBeNull()
        ->and($week()->override_date)->toBeNull()
        ->and($day()->prescription_budget)->toBeNull()
        ->and($day()->prescribed_hard_minutes)->toBe($fullDose);
});

/**
 * Pinned tempos on Monday and Tuesday fill the week's two hard days, so the count makes Thursday's quality easy.
 *
 * @return array{user: User, dropped: PlannedSession, day: Closure(): PlannedSession, week: Closure(): PlanAdaptation}
 */
function hardDayDroppedWeek(): array
{
    Carbon::setTestNow('2026-08-10 06:00:00');
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->subWeeks($i)->toDateString(), 'runs' => 4, 'distance_km' => 30.0]);
    }
    foreach ([2, 4, 5, 7, 9, 11, 12, 14, 16, 18, 19, 21, 23, 25, 26, 28] as $daysAgo) {
        ActivityDetail::factory()->for(Activity::factory()->for($user))->create(['start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(6, 0), 'distance' => 7_500, 'moving_time' => 2_475]);
    }
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    seedConfirmedEffort($user, 10_000, 2700, Carbon::today());
    foreach (['2026-08-10', '2026-08-11'] as $date) {
        PlannedSession::factory()->for($user)->create(['date' => $date, 'session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 5, 'pinned' => true]);
    }
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $dropped = PlannedSession::query()->where('user_id', $user->id)->where('hard_day_restorable', true)->orderBy('date')->firstOrFail();

    return [
        'user' => $user,
        'dropped' => $dropped,
        'day' => static fn (): PlannedSession => PlannedSession::query()->where('user_id', $user->id)->whereDate('date', $dropped->date)->firstOrFail(),
        'week' => static fn (): PlanAdaptation => PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail(),
    ];
}

it('brings back a session the weekly hard-day count made easy, and makes it easy again on undo', function (): void {
    ['user' => $user, 'dropped' => $dropped, 'day' => $day, 'week' => $week] = hardDayDroppedWeek();

    expect(overrideService()->take($user, $dropped, Carbon::today()))->toBe(PlanOverrideKind::HardDayDrop)
        ->and($day()->hard_day_restorable)->toBeFalse()
        ->and($day()->prescribed_hard_minutes)->toBeGreaterThan(0);

    overrideService()->undo($user, $day(), Carbon::today());

    expect($day()->hard_day_restorable)->toBeTrue()
        ->and($day()->prescribed_hard_minutes)->toBe(0)
        ->and($week()->override_kind)->toBeNull();
});

it('hands a hard-day bring-back back once the week no longer has two other hard days, and keeps it while it does', function (): void {
    ['user' => $user, 'dropped' => $dropped, 'day' => $day, 'week' => $week] = hardDayDroppedWeek();
    overrideService()->take($user, $dropped, Carbon::today());
    $dose = $day()->prescribed_hard_minutes;

    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect($week()->override_kind)->toBe(PlanOverrideKind::HardDayDrop);

    PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-11')->delete();
    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect($week()->override_kind)->toBeNull()
        ->and($day()->hard_day_restorable)->toBeFalse()
        ->and($day()->prescribed_hard_minutes)->toBe($dose);
});
