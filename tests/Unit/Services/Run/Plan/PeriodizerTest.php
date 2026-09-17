<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Feedback;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->periodizer = app(Periodizer::class);
});
afterEach(fn () => Carbon::setTestNow());

function seedPeriodizerBaseline(User $user): void
{
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }
}

it('generates a self-scaled build/deload cycle when the user has no active race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());

    $phases = PlannedSession::query()->where('user_id', $user->id)->pluck('phase')->map(fn ($p) => $p->value)->unique()->sort()->values()->all();
    expect($phases)->toBe(['build', 'deload']);
});

it('generates a race-oriented base/build/peak/taper progression when an active race exists', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    // Close enough (weeksToRace = 10) that base/build/peak/taper all fall
    // within the periodizer's 12-week materialization horizon.
    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(9)->toDateString(),
        'distance_m' => 10_000,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $phases = PlannedSession::query()->where('user_id', $user->id)->pluck('phase')->map(fn ($p) => $p->value)->unique()->all();
    // A recovery week sits inside the base/build ramp — see
    // docs/decisions/the-plan-follows-the-coaching.md.
    expect($phases)->toContain('base', 'build', 'peak', 'taper');
});

it('holds a general week before block open flat instead of ramping it', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(20)->toDateString(),
        'distance_m' => 10_000,
    ]);
    Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'anchor_weekly_volume_km' => 30.0,
        'starts_at' => Carbon::today()->subWeeks(10)->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    // Week 11 of a 31-week arc: Build x1.156 when the ramp counted from the season start.
    $multipliers = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', ['2026-08-10', '2026-08-16'])
        ->pluck('volume_multiplier')
        ->unique()
        ->values()
        ->all();
    expect($multipliers)->toBe([1.0]);
});

it('never overwrites a pinned row', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $pinned = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'session_type' => SessionType::Interval,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $fresh = $pinned->fresh();
    expect($fresh->session_type)->toBe(SessionType::Interval)
        ->and($fresh->pinned)->toBeTrue();
});

it('never touches a row dated before today', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $past = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'session_type' => SessionType::Rest,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    expect($past->fresh()->session_type)->toBe(SessionType::Rest);
});

it('recomputes a stale unpinned future row fresh on a second call', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());
    $someRow = PlannedSession::query()
        ->where('user_id', $user->id)
        ->where('date', '>=', Carbon::today()->toDateString())
        ->first();
    $dateKey = $someRow->date->toDateString();
    $someRow->update(['session_type' => SessionType::Interval, 'phase' => PlanPhase::Peak]);

    $this->periodizer->regenerate($user, Carbon::today());

    // Self-scaled mode never produces Peak, so a still-Peak row would prove the
    // stale edit survived instead of being recomputed.
    $refetched = PlannedSession::query()->where('user_id', $user->id)->where('date', $dateKey)->first();
    expect($refetched)->not->toBeNull()
        ->and($refetched->phase)->not->toBe(PlanPhase::Peak);
});

it('cleans up stale far-future rows when the horizon shrinks after setting a near-term race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());
    $farFutureDate = Carbon::today()->addWeeks(10)->toDateString();
    expect(PlannedSession::query()->where('user_id', $user->id)->where('date', '>=', $farFutureDate)->exists())->toBeTrue();

    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(3)->toDateString(),
        'distance_m' => 10_000,
    ]);
    $this->periodizer->regenerate($user, Carbon::today());

    expect(PlannedSession::query()->where('user_id', $user->id)->where('date', '>=', $farFutureDate)->exists())->toBeFalse();
});

it('also ensures a current season exists, in lockstep with the plan\'s own mode', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());

    expect(Season::query()->where('user_id', $user->id)->where('race_goal_id', null)->exists())->toBeTrue();
});

it('leaves a pinned far-future row alone even when the horizon shrinks', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $pinned = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addWeeks(11)->toDateString(),
    ]);

    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(3)->toDateString(),
        'distance_m' => 10_000,
    ]);
    $this->periodizer->regenerate($user, Carbon::today());

    expect(PlannedSession::query()->find($pinned->id))->not->toBeNull();
});

function currentWeekQualityCount(User $user): int
{
    return PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [
            Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
            Carbon::today()->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString(),
        ])
        ->whereIn('session_type', [SessionType::Tempo, SessionType::Interval])
        ->count();
}

function regenerateWithProjectedFinish(User $user, float $predictedSec): void
{
    $riegel = Mockery::mock(RiegelProjector::class);
    $riegel->shouldReceive('project')->andReturn([
        'predicted_sec' => $predictedSec, 'low_sec' => $predictedSec * 0.95, 'high_sec' => $predictedSec * 1.05,
        'exponent' => 1.06, 'sample_size' => 3, 'confidence' => 'medium',
    ]);
    app()->instance(RiegelProjector::class, $riegel);
    app()->forgetInstance(PlanAdapter::class);

    app(Periodizer::class)->regenerate($user, Carbon::today());
}

it('records what it decided about the current week', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());

    $adaptation = PlanAdaptation::query()
        ->where('user_id', $user->id)
        ->where('week_start', Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString())
        ->firstOrFail();

    expect($adaptation->reason)->toBe(AdaptationReason::Steady)
        ->and($adaptation->deload)->toBeFalse();
});

it('re-records the current week\'s decision on a second regeneration rather than duplicating it', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());
    $this->periodizer->regenerate($user, Carbon::today());

    expect(PlanAdaptation::query()->where('user_id', $user->id)->count())->toBe(1);
});

function bindMonotonyDeloadSignals(): void
{
    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'weekly_trimp' => 400.0, 'atl_7d' => 50.0, 'ctl_42d' => 40.0,
        'form' => 5.0, 'form_status' => 'optimal',
        'monotony' => PlanAdapter::MONOTONY_DELOAD + 0.5, 'strain' => 500.0,
    ]);
    app()->instance(TrainingLoad::class, $trainingLoad);
    app()->forgetInstance(PlanAdapter::class);
}

function currentWeekPhases(User $user): array
{
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    return PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
        ->pluck('phase')
        ->unique()
        ->values()
        ->all();
}

it('turns the current week into a real deload when monotony says so', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    bindMonotonyDeloadSignals();

    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect(currentWeekPhases($user))->toBe([PlanPhase::Deload])
        ->and(currentWeekQualityCount($user))->toBe(0)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->reason)
        ->toBe(AdaptationReason::HighMonotony);
});

it('never deloads a taper week, where freshness is already the goal', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addDays(5)->toDateString(),
        'distance_m' => 10_000,
    ]);
    bindMonotonyDeloadSignals();

    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect(currentWeekPhases($user))->toBe([PlanPhase::Taper]);
});

it('an explicit sessions_per_week preference overrides the behavioral session count', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2, 'run_days' => null, 'long_run_day' => null]);

    $this->periodizer->regenerate($user, Carbon::today());

    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $nonRest = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
        ->where('session_type', '!=', SessionType::Rest)
        ->count();

    expect($nonRest)->toBe(2);
});

it('an explicit run_days/long_run_day preference places sessions on the chosen weekdays', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    TrainingPreference::factory()->for($user)->create(['run_days' => [0, 2, 4], 'long_run_day' => 4]);

    $this->periodizer->regenerate($user, Carbon::today());

    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $monday = PlannedSession::query()->where('user_id', $user->id)->where('date', $weekStart->toDateString())->firstOrFail();
    $friday = PlannedSession::query()->where('user_id', $user->id)->where('date', $weekStart->copy()->addDays(4)->toDateString())->firstOrFail();
    $tuesday = PlannedSession::query()->where('user_id', $user->id)->where('date', $weekStart->copy()->addDays(1)->toDateString())->firstOrFail();

    expect($monday->session_type)->not->toBe(SessionType::Rest)
        ->and($friday->session_type)->toBe(SessionType::Long)
        ->and($tuesday->session_type)->toBe(SessionType::Rest);
});

it('lets the race projection move prescribed quality work in both directions', function (): void {
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 6,
            'distance_km' => 60.0,
        ]);
    }
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(11)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 6000,
    ]);
    // The block opened four weeks ago, so the current week is a Build one:
    // Base carries at most one threshold session whatever the adapter says.
    Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'starts_at' => Carbon::today()->subWeeks(8)->startOfWeek(Carbon::MONDAY)->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
        'anchor_weekly_volume_km' => 60.0,
    ]);

    regenerateWithProjectedFinish($user, 6100.0); // inside the margin: steady
    $steady = currentWeekQualityCount($user);

    regenerateWithProjectedFinish($user, 7200.0);
    $behindGoal = currentWeekQualityCount($user);

    regenerateWithProjectedFinish($user, 5000.0);
    $aheadOfGoal = currentWeekQualityCount($user);

    // #933: being ahead of the goal time no longer touches the quality block
    // at all -- it stays exactly what the phase baseline already prescribes.
    // Only falling behind still moves it, and only upward.
    expect($behindGoal)->toBeGreaterThan($steady)
        ->and($aheadOfGoal)->toBe($steady);
});

it('writes race day into the plan and stamps the distance onto the row', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $raceDate = Carbon::today()->addWeeks(4)->startOfWeek(Carbon::MONDAY)->addDays(6);
    RaceGoal::factory()->for($user)->create([
        'race_date' => $raceDate,
        'distance_m' => 21_097,
        'completed_at' => null,
    ]);

    $this->periodizer->regenerate($user);

    $raceRow = PlannedSession::query()->where('user_id', $user->id)
        ->where('date', $raceDate->toDateString())->firstOrFail();

    expect($raceRow->session_type)->toBe(SessionType::Race)
        ->and($raceRow->race_distance_m)->toBe(21_097);
});

it('stamps no race distance on any day that is not the race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(4),
        'distance_m' => 21_097,
        'completed_at' => null,
    ]);

    $this->periodizer->regenerate($user);

    $stamped = PlannedSession::query()->where('user_id', $user->id)
        ->whereNotNull('race_distance_m')->get();

    expect($stamped)->toHaveCount(1)
        ->and($stamped->first()->session_type)->toBe(SessionType::Race);
});

it('plans no race day at all once the athlete clears their race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(4),
        'distance_m' => 21_097,
        'completed_at' => null,
    ]);
    $this->periodizer->regenerate($user);

    $race->update(['completed_at' => now()]);
    $this->periodizer->regenerate($user);

    expect(PlannedSession::query()->where('user_id', $user->id)->where('session_type', SessionType::Race)->exists())->toBeFalse()
        ->and(PlannedSession::query()->where('user_id', $user->id)->whereNotNull('race_distance_m')->exists())->toBeFalse();
});

it('leaves nothing behind when a near-term race shrinks the horizon, and refills it when the race is cleared', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user);
    $selfScaledEnd = PlannedSession::query()->where('user_id', $user->id)->max('date');

    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(3),
        'distance_m' => 10_000,
        'completed_at' => null,
    ]);
    $this->periodizer->regenerate($user);

    $raceArcEnd = Carbon::today()->addWeeks(3)->endOfWeek(Carbon::SUNDAY)->toDateString();
    expect(PlannedSession::query()->where('user_id', $user->id)->max('date'))->toBe($raceArcEnd)
        // The shrink leaves no row from the longer arc it replaced.
        ->and(PlannedSession::query()->where('user_id', $user->id)->where('date', '>', $raceArcEnd)->exists())->toBeFalse();

    $race->update(['completed_at' => now()]);
    $this->periodizer->regenerate($user);

    expect(PlannedSession::query()->where('user_id', $user->id)->max('date'))->toBe($selfScaledEnd);
});

it('carries a recorded easy clamp onto today\'s recreated row', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $today = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Tempo,
        'clamped_km' => 3.6,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $fresh = PlannedSession::query()->where('user_id', $user->id)->where('date', Carbon::today()->toDateString())->firstOrFail();
    expect($fresh->id)->not->toBe($today->id)
        ->and($fresh->clamped_km)->toBe(3.6)
        ->and($fresh->rest_clamped_at)->toBeNull();

    $effective = EffectiveSession::of($fresh, 8.0);
    expect($effective->sessionType)->toBe(SessionType::Easy)
        ->and($effective->coreKm)->toBe(3.6);
});

it('carries a recorded pace ease onto today\'s recreated row', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $today = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Long,
        'eased_pace_sec_per_km' => 375,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $fresh = PlannedSession::query()->where('user_id', $user->id)->where('date', Carbon::today()->toDateString())->firstOrFail();
    expect($fresh->id)->not->toBe($today->id)
        ->and($fresh->eased_pace_sec_per_km)->toBe(375)
        ->and($fresh->clamped_km)->toBeNull()
        ->and($fresh->rest_clamped_at)->toBeNull();

    // Unlike clamped_km/rest_clamped_at, a pace ease never overrides the
    // session type it rides along on — EffectiveSession reads it back
    // against whatever type the regenerated row actually carries.
    $effective = EffectiveSession::of($fresh, 20.0);
    expect($effective->sessionType)->toBe($fresh->session_type)
        ->and($effective->coreKm)->toBe(20.0)
        ->and($effective->isPaceEased())->toBeTrue()
        ->and($effective->easedPaceSecPerKm)->toBe(375);
});

it('carries a recorded rest clamp onto today\'s recreated row, keeping the day excused', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $today = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Long,
        'rest_clamped_at' => Carbon::now(),
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $fresh = PlannedSession::query()->where('user_id', $user->id)->where('date', Carbon::today()->toDateString())->firstOrFail();
    expect($fresh->id)->not->toBe($today->id)
        ->and($fresh->rest_clamped_at)->not->toBeNull()
        ->and($fresh->isExcused())->toBeTrue();
});

it('deletes plan_day feedback for exactly the rows a regenerate deletes, keeping flags elsewhere', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $deletedRow = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDays(1)->toDateString(),
    ]);
    $pinnedRow = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    $gradedRow = PlannedSession::factory()->for($user)->scored()->create([
        'date' => Carbon::today()->addDays(3)->toDateString(),
    ]);

    $deletedFlag = Feedback::factory()->for($user)->onPlanDay($deletedRow->id)->create();
    $pinnedFlag = Feedback::factory()->for($user)->onPlanDay($pinnedRow->id)->create();
    $gradedFlag = Feedback::factory()->for($user)->onPlanDay($gradedRow->id)->create();
    $narrationFlag = Feedback::factory()->for($user)->onNarration(999)->create();

    $this->periodizer->regenerate($user, Carbon::today());

    expect(Feedback::query()->find($deletedFlag->id))->toBeNull()
        ->and(Feedback::query()->find($pinnedFlag->id))->not->toBeNull()
        ->and(Feedback::query()->find($gradedFlag->id))->not->toBeNull()
        ->and(Feedback::query()->find($narrationFlag->id))->not->toBeNull();
});

it('keeps today\'s row when the day has already been scored', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $scored = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Long,
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 104,
        'prescribed_km' => 12.0,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $fresh = $scored->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(PlannedSessionStatus::Done)
        ->and($fresh->compliance_score)->toBe(104)
        ->and($fresh->session_type)->toBe(SessionType::Long);
});
