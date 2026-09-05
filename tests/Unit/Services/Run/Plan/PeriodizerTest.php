<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\TrainingLoad;
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
    expect($phases)->toContain('base', 'build', 'peak', 'taper')
        ->and($phases)->not->toContain('deload');
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
    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(12)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 6000,
    ]);

    regenerateWithProjectedFinish($user, 7200.0);
    $behindGoal = currentWeekQualityCount($user);

    regenerateWithProjectedFinish($user, 5000.0);
    $aheadOfGoal = currentWeekQualityCount($user);

    expect($behindGoal)->toBeGreaterThan($aheadOfGoal)
        ->and($aheadOfGoal)->toBe(0);
});
