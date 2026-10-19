<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Enums\AdaptationReason;
use App\Enums\FallOffTilt;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\RaceAmbitionState;
use App\Enums\SessionType;
use App\Models\Feedback;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanInputsGatherer;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\PlanAdapter;
use App\Services\Run\Story\PastYouTrendBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
});
afterEach(fn () => Carbon::setTestNow());

function periodizer(): Periodizer
{
    return app(Periodizer::class);
}

it('keeps carried readiness doses under new workload limits during reconciliation and regeneration', function (bool $fullRegeneration): void {
    Carbon::setTestNow('2026-08-13 08:00:00');
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    WeeklySnapshot::query()->where('user_id', $user->id)->update(['distance_km' => 60.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => '2026-08-08 07:00:00', 'distance' => 20_000]);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    PersonalRecord::factory()->for($user)->create(['category' => '10km', 'value_sec' => 2700, 'set_at' => Carbon::today()]);
    seedConfirmedEffort($user, 10_000, 2700, Carbon::today());
    periodizer()->regenerate($user, Carbon::today());
    $session = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', Carbon::today())->firstOrFail();
    $assessment = ['adjustment' => ['quality_dose' => ['hard_minutes' => 15, 'original_hard_minutes' => 20, 'pace_band' => 'threshold', 'pace_sec_per_km' => 270]]];
    $session->update(['clamped_km' => 12.0, 'readiness_assessment' => $assessment]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-08-10 07:00:00', 'elapsed_time' => 10800,
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 0, 'Z3' => 0, 'Z4' => 180, 'Z5' => 0]],
    ]);
    if ($fullRegeneration) {
        periodizer()->regenerate($user, Carbon::today());
    } else {
        expect(periodizer()->regenerateIfChanged($user, Carbon::today()))->toBeTrue();
    }
    $session = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', Carbon::today())->firstOrFail();
    $effective = EffectiveSession::of($session, 10.0);
    expect($session->prescribed_hard_minutes)->toBe(0)
        ->and($session->readiness_assessment)->toEqual($assessment)
        ->and($effective->qualityPrescription())->toBeNull()
        ->and($effective->sessionType)->toBe(SessionType::Easy)
        ->and($effective->coreKm)->toBe(12.0);
})->with(['reconciliation' => false, 'full regeneration' => true]);

it('reconciles new actual workload only into affected current-week prescriptions', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    WeeklySnapshot::query()->where('user_id', $user->id)->update(['distance_km' => 60.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => '2026-08-08 07:00:00', 'distance' => 20_000]);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    PersonalRecord::factory()->for($user)->create(['category' => '10km', 'value_sec' => 2700, 'set_at' => Carbon::today()]);
    seedConfirmedEffort($user, 10_000, 2700, Carbon::today());
    periodizer()->regenerate($user, Carbon::today());
    $thursday = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-13')->firstOrFail();
    $nextWeek = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-20')->firstOrFail();
    expect($thursday->prescribed_hard_minutes)->toBeGreaterThan(0);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-08-10 07:00:00', 'elapsed_time' => 10800,
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 0, 'Z3' => 0, 'Z4' => 180, 'Z5' => 0]],
    ]);

    expect(periodizer()->regenerateIfChanged($user, Carbon::today()))->toBeTrue()
        ->and($thursday->refresh()->prescribed_hard_minutes)->toBe(0)
        ->and($nextWeek->refresh()->id)->toBe($nextWeek->id)
        ->and(periodizer()->regenerateIfChanged($user, Carbon::today()))->toBeFalse();
});

it('still plans today when a run is already logged today', function (): void {
    Carbon::setTestNow('2026-08-13 18:00:00');
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-08-13 07:00:00', 'elapsed_time' => 2400, 'moving_time' => 2400, 'distance' => 7000,
    ]);

    periodizer()->regenerate($user, Carbon::today());

    expect(PlannedSession::query()->where('user_id', $user->id)->whereDate('date', Carbon::today())->count())->toBe(1);
});

it('keeps a day the athlete skipped in advance when the plan regenerates', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    periodizer()->regenerate($user, Carbon::today());
    $future = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-14')->firstOrFail();
    $future->update(['skipped' => true]);

    periodizer()->regenerate($user, Carbon::today());

    expect($future->fresh()?->skipped)->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-14')->count())->toBe(1);
});

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

it('generates a self-scaled cycle with its season and decision, then regenerates it in constant queries, leaving settled sessions alone', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $resolve = app(ResolvePlannedSessionsAction::class);
    $today = Carbon::today()->toDateString();
    expect($resolve($user->id, $today, $today))->toBeEmpty();
    Cache::put(PastYouTrendBuilder::cacheKey($user->id, $today), ['stale'], 3600);

    periodizer()->regenerate($user, Carbon::today());

    expect($resolve($user->id, $today, $today))->toHaveCount(1)
        ->and(Cache::has(PastYouTrendBuilder::cacheKey($user->id, $today)))->toBeFalse();

    $phases = PlannedSession::query()->where('user_id', $user->id)->get()->map(fn (PlannedSession $row): string => $row->phase->value)->unique()->sort()->values()->all();
    expect($phases)->toBe(['build', 'deload'])
        ->and(Season::query()->where('user_id', $user->id)->where('race_goal_id', null)->exists())->toBeTrue();

    $adaptation = PlanAdaptation::query()
        ->where('user_id', $user->id)
        ->where('week_start', Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString())
        ->firstOrFail();

    expect($adaptation->reason)->toBe(AdaptationReason::Steady)
        ->and($adaptation->deload)->toBeFalse();

    DB::flushQueryLog();
    DB::enableQueryLog();
    periodizer()->regenerate($user, Carbon::today());
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $plannedSessionInserts = array_filter($queries, fn (string $sql): bool => str_starts_with(strtolower($sql), 'insert into `planned_sessions`'));
    expect(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(Periodizer::HORIZON_WEEKS * 7)
        ->and($plannedSessionInserts)->toHaveCount(1)
        ->and(count($queries))->toBeLessThanOrEqual(24)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->count())->toBe(1);

    $before = PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail();
    Carbon::setTestNow('2026-08-10 08:05:00');

    expect(app(Periodizer::class)->regenerateIfChanged($user, Carbon::today()))->toBeFalse()
        ->and(nonNull(nonNull($before->fresh())->updated_at)->equalTo(nonNull($before->updated_at)))->toBeTrue();

    $sessionOn = fn (int $days): PlannedSession => PlannedSession::query()->where('user_id', $user->id)->whereDate('date', Carbon::today()->addDays($days))->firstOrFail();
    $sessionOn(0)->update(['status' => PlannedSessionStatus::Done, 'compliance_score' => 96, 'distance_score' => 98, 'intent_verdict' => 'hit', 'intent_evidence' => ['effective_type' => 'easy']]);
    $sessionOn(2)->update(['skipped' => true]);
    $sessionOn(9)->update(['status' => PlannedSessionStatus::Partial, 'compliance_score' => 70]);
    $settledAttributes = fn (): array => collect([0, 2, 9])->mapWithKeys(fn (int $days): array => [$days => $sessionOn($days)->getAttributes()])->all();
    $settled = $settledAttributes();
    $replaced = $sessionOn(1)->id;
    $this->travel(5)->minutes();

    periodizer()->regenerate($user, Carbon::today());

    expect($settledAttributes())->toBe($settled)
        ->and($sessionOn(1)->id)->not->toBe($replaced)
        ->and(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(Periodizer::HORIZON_WEEKS * 7);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>
 */
function periodizerRows(string $today, ?string $raceDay, array $overrides = []): array
{
    return app(Periodizer::class)->rowsFor(planInputsWith(arcInputs(today: $today, raceDay: $raceDay), [
        'seasonStart' => Carbon::parse($today),
        ...$overrides,
    ]));
}

/**
 * @param  array<string, array{session_type: SessionType, ...}>  $rows
 * @return list<SessionType>
 */
function weekTypes(array $rows, string $monday): array
{
    return array_map(
        static fn (int $offset): SessionType => $rows[Carbon::parse($monday)->addDays($offset)->toDateString()]['session_type'],
        range(0, 6),
    );
}

it('generates a race-oriented base/build/peak/taper progression when an active race exists', function (): void {
    $rows = periodizerRows('2026-08-10', '2026-10-12', ['longRunBaselineKm' => 15.0, 'longRunCapKm' => 15.0, 'longRunProgressionCapKm' => 5.3]);

    $phases = array_values(array_unique(array_map(fn (array $row): string => $row['phase']->value, $rows)));
    expect($phases)->toContain('base', 'build', 'peak', 'taper');
});

it('holds a general week before block open flat instead of ramping it', function (): void {
    $rows = periodizerRows('2026-08-10', '2026-12-28', ['seasonStart' => Carbon::parse('2026-06-01'), 'longRunBaselineKm' => 9.0, 'longRunCapKm' => 15.0, 'longRunProgressionCapKm' => 5.3]);

    // Week 11 of a 31-week arc: Build x1.156 when the ramp counted from the season start.
    $multipliers = collect(range(0, 6))
        ->map(fn (int $offset): float => $rows[Carbon::parse('2026-08-10')->addDays($offset)->toDateString()]['volume_multiplier'])
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

    periodizer()->regenerate($user, Carbon::today());

    $fresh = $pinned->refresh();
    expect($fresh->session_type)->toBe(SessionType::Interval)
        ->and($fresh->pinned)->toBeTrue();
});

it('keeps a session pinned after input gathering and rebuilds the week around it', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    Season::factory()->for($user)->create(['anchor_weekly_volume_km' => 30.0]);
    $session = PlannedSession::factory()->for($user)->rest()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'pinned' => false,
    ]);
    $feedback = Feedback::factory()->for($user)->onPlanDay($session->id)->create();
    $injected = false;

    $vdotEstimator = Mockery::mock(VdotEstimator::class)->makePartial();
    $vdotEstimator->shouldReceive('estimate')->andReturnUsing(function (User $user, ?Carbon $asOf) use (&$injected, $session): ?array {
        if (! $injected) {
            $injected = true;
            $session->update([
                'session_type' => SessionType::Tempo,
                'prescribed_hard_minutes' => 20,
                'prescribed_pace_band' => PaceBand::Threshold,
                'prescribed_pace_sec_per_km' => 330,
                'pinned' => true,
            ]);
        }

        return null;
    });
    app()->instance(VdotEstimator::class, $vdotEstimator);
    app()->forgetInstance(TrainingBaseline::class);
    app()->forgetInstance(SeasonService::class);
    app()->forgetInstance(PlanInputsGatherer::class);
    app()->forgetInstance(Periodizer::class);

    app(Periodizer::class)->regenerate($user, Carbon::today());

    $fresh = $session->refresh();
    expect($injected)->toBeTrue()
        ->and($fresh->session_type)->toBe(SessionType::Tempo)
        ->and($fresh->prescribed_hard_minutes)->toBe(20)
        ->and($fresh->pinned)->toBeTrue()
        ->and($feedback->fresh())->not->toBeNull();

    $hardDates = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(), Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString()])
        ->whereIn('session_type', [SessionType::Tempo, SessionType::Interval])
        ->orderBy('date')
        ->get()
        ->map(fn (PlannedSession $row): Carbon => $row->date);

    for ($index = 1; $index < $hardDates->count(); $index++) {
        expect(Carbon::parse($hardDates[$index])->diffInDays(Carbon::parse($hardDates[$index - 1])))->toBeGreaterThanOrEqual(2);
    }
});

it('rolls back deleted sessions and feedback when a regenerated row cannot be written', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => SessionType::Tempo,
        'pinned' => false,
    ]);
    $feedback = Feedback::factory()->for($user)->onPlanDay($session->id)->create();
    $before = nonNull($session->fresh())->getAttributes();
    $failed = false;

    DB::listen(function (QueryExecuted $query) use (&$failed): void {
        if (! $failed && str_starts_with(strtolower($query->sql), 'insert into `planned_sessions`')) {
            $failed = true;
            throw new RuntimeException('planned session regeneration failed');
        }
    });

    expect(fn () => app(Periodizer::class)->regenerate($user, Carbon::today()))
        ->toThrow(RuntimeException::class, 'planned session regeneration failed');

    expect($failed)->toBeTrue()
        ->and(nonNull($session->fresh())->getAttributes())->toBe($before)
        ->and($feedback->fresh())->not->toBeNull()
        ->and(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('never touches a row dated before today', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $past = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'session_type' => SessionType::Rest,
    ]);

    periodizer()->regenerate($user, Carbon::today());

    expect($past->refresh()->session_type)->toBe(SessionType::Rest);
});

it('recomputes a stale unpinned future row fresh on a second call', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    periodizer()->regenerate($user, Carbon::today());
    $someRow = nonNull(PlannedSession::query()
        ->where('user_id', $user->id)
        ->where('date', '>=', Carbon::today()->toDateString())
        ->first());
    $dateKey = $someRow->date->toDateString();
    $someRow->update(['session_type' => SessionType::Interval, 'phase' => PlanPhase::Peak]);

    periodizer()->regenerate($user, Carbon::today());

    // Self-scaled mode never produces Peak, so a still-Peak row would prove the
    // stale edit survived instead of being recomputed.
    $refetched = nonNull(PlannedSession::query()->where('user_id', $user->id)->where('date', $dateKey)->first());
    expect($refetched->phase)->not->toBe(PlanPhase::Peak);
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
    periodizer()->regenerate($user, Carbon::today());

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

it('reconciles when a settled key-session verdict changes the adaptation fingerprint', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    periodizer()->regenerate($user, Carbon::today());

    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subWeek()->toDateString(),
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'distance_score' => 100,
        'compliance_score' => 84,
        'intent_verdict' => 'missed',
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
    ]);

    $changed = periodizer()->regenerateIfChanged($user, Carbon::today());
    $adaptation = PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail();

    expect($changed)->toBeTrue()
        ->and($adaptation->reason)->toBe(AdaptationReason::MissedStimulus)
        ->and($adaptation->stimulus_adherence_pct)->toBe(0);
});

it('does not restore a quality slot removed by an earlier repeated miss', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    foreach (['2026-07-27', '2026-08-03'] as $date) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => SessionType::Tempo,
            'status' => PlannedSessionStatus::Partial,
            'distance_score' => 100,
            'compliance_score' => 60,
            'intent_verdict' => 'missed',
            'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
        ]);
    }

    periodizer()->regenerate($user, Carbon::today());
    PlannedSession::query()->where('user_id', $user->id)->whereIn('date', ['2026-07-27', '2026-08-03'])->update([
        'intent_verdict' => 'hit',
        'intent_evidence' => json_encode(['advice_history' => 'shown', 'quality_progression' => 'eligible']),
    ]);

    expect(periodizer()->regenerateIfChanged($user, Carbon::today()))->toBeFalse()
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->quality_delta)
        ->toBe(-1);
});

it('reconciles a settled key-session verdict from the current week', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    periodizer()->regenerate($user, Carbon::today());

    PlannedSession::query()
        ->where('user_id', $user->id)
        ->where('date', '2026-08-11')
        ->update([
            'session_type' => SessionType::Tempo,
            'status' => PlannedSessionStatus::Partial,
            'distance_score' => 100,
            'compliance_score' => 60,
            'intent_verdict' => 'missed',
            'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
        ]);

    if (! PlannedSession::query()->where('user_id', $user->id)->where('date', '2026-08-11')->exists()) {
        PlannedSession::factory()->for($user)->create([
            'date' => '2026-08-11',
            'session_type' => SessionType::Tempo,
            'status' => PlannedSessionStatus::Partial,
            'distance_score' => 100,
            'compliance_score' => 60,
            'intent_verdict' => 'missed',
            'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
        ]);
    }

    expect(periodizer()->regenerateIfChanged($user, Carbon::today()))->toBeTrue()
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->reason)
        ->toBe(AdaptationReason::MissedStimulus);
});

/** @return array<int, PlanPhase> */
function currentWeekPhases(User $user): array
{
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    return PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
        ->get()
        ->map(fn (PlannedSession $row): PlanPhase => $row->phase)
        ->unique()
        ->values()
        ->all();
}

it('turns the current week into a real deload after a missed week, with no floor for a goal-less season, and keeps it on an unchanged reading', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    seedMissedLastWeek($user);

    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect(currentWeekPhases($user))->toBe([PlanPhase::Deload])
        ->and(currentWeekQualityCount($user))->toBe(0)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->reason)
        ->toBe(AdaptationReason::MissedWeek)
        ->and(floorOverriddenKm($user))->toBeNull();

    expect(periodizer()->regenerateIfChanged($user, Carbon::today()))->toBeFalse()
        ->and(currentWeekPhases($user))->toBe([PlanPhase::Deload])
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->reason)
        ->toBe(AdaptationReason::MissedWeek);
});

function floorOverriddenKm(User $user): ?float
{
    return PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail()->volume_floor_km;
}

it('records the volume floor a load deload takes the week under', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString(), 'distance_m' => 10_000]);
    seedMissedLastWeek($user);

    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect(currentWeekPhases($user))->toBe([PlanPhase::Deload])
        ->and(floorOverriddenKm($user))->toBe(Season::query()->where('user_id', $user->id)->value('volume_floor_km'))
        ->and(floorOverriddenKm($user))->not->toBeNull();
});

it('never deloads a taper week, and records no overridden floor when the week stands or a taper week is left alone', function (): void {
    $steady = User::factory()->create();
    seedPeriodizerBaseline($steady);
    RaceGoal::factory()->for($steady)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString(), 'distance_m' => 10_000]);
    app(Periodizer::class)->regenerate($steady, Carbon::today());

    $tapering = User::factory()->create();
    seedPeriodizerBaseline($tapering);
    RaceGoal::factory()->for($tapering)->create(['race_date' => Carbon::today()->addDays(5)->toDateString(), 'distance_m' => 10_000]);
    seedMissedLastWeek($tapering);
    app(Periodizer::class)->regenerate($tapering, Carbon::today());

    expect(floorOverriddenKm($steady))->toBeNull()
        ->and(floorOverriddenKm($tapering))->toBeNull()
        ->and(currentWeekPhases($tapering))->toBe([PlanPhase::Taper]);
});

it('an explicit sessions_per_week preference overrides the behavioral session count', function (): void {
    $rows = periodizerRows('2026-08-10', null, ['sessionsPerWeek' => 2, 'runDays' => null, 'longRunDay' => null, 'longRunBaselineKm' => 12.5]);

    $nonRest = array_filter(weekTypes($rows, '2026-08-10'), fn (SessionType $type): bool => $type !== SessionType::Rest);

    expect($nonRest)->toHaveCount(2);
});

it('an explicit run_days/long_run_day preference places sessions on the chosen weekdays', function (): void {
    $rows = periodizerRows('2026-08-10', null, ['runDays' => [0, 2, 4], 'longRunDay' => 4, 'longRunBaselineKm' => 15.0]);

    [$monday, $tuesday, , , $friday] = weekTypes($rows, '2026-08-10');

    expect($monday)->not->toBe(SessionType::Rest)
        ->and($friday)->toBe(SessionType::Long)
        ->and($tuesday)->toBe(SessionType::Rest);
});

it('counts a race-pace Long against the weekly hard-day limit', function (): void {
    $rows = periodizerRows('2026-08-10', '2026-10-05', ['sessionsPerWeek' => 6, 'projectedRaceSeconds' => 10_800.0, 'raceGoalTimeSec' => 10_800, 'raceDistanceM' => 42_195.0, 'volumeFloorKm' => 60.0, 'longRunBaselineKm' => 28.0, 'longRunCapKm' => 30.0, 'longRunProgressionCapKm' => 5.3, 'raceAmbitionState' => RaceAmbitionState::Unknown]);

    $buildDate = array_key_first(array_filter($rows, fn (array $row): bool => $row['phase'] === PlanPhase::Build));
    expect($buildDate)->not->toBeNull();

    $monday = Carbon::parse($buildDate)->startOfWeek(Carbon::MONDAY);
    $week = array_filter($rows, fn (string $date): bool => $date >= $monday->toDateString() && $date <= $monday->copy()->addDays(6)->toDateString(), ARRAY_FILTER_USE_KEY);
    $long = nonNull(array_find($week, fn (array $row): bool => $row['session_type'] === SessionType::Long));
    $quality = array_filter($week, fn (array $row): bool => in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true));
    $hardDays = array_filter($week, fn (array $row): bool => $row['prescribed_hard_minutes'] > 0);

    expect($long['prescribed_pace_band'])->toBe(PaceBand::Marathon);
    expect($quality)->toHaveCount(1);
    expect(nonNull(array_first($quality))['prescribed_hard_minutes'])->toBeGreaterThan(0);
    expect($hardDays)->toHaveCount(2);
});

it('caps future quality around a settled tempo and a race-pace Long in the current week', function (): void {
    $rows = periodizerRows('2026-09-03', '2026-09-28', [
        'sessionsPerWeek' => 6,
        'projectedRaceSeconds' => 12_000.0,
        'raceGoalTimeSec' => 12_000,
        'raceDistanceM' => 42_195.0,
        'volumeFloorKm' => 60.0,
        'longRunBaselineKm' => 30.0,
        'longRunCapKm' => 30.0,
        'longRunProgressionCapKm' => 11.0,
        'raceAmbitionState' => RaceAmbitionState::Unknown,
        'fixedSessions' => ['2026-09-01' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 20, 'prescribed_pace_band' => PaceBand::Threshold, 'hard_minutes' => null]],
        'actualSessions' => [['date' => '2026-09-01', 'duration_minutes' => 60, 'hard_minutes' => 20.0, 'demanding' => true]],
    ]);

    expect($rows['2026-09-03']['session_type'])->toBe(SessionType::Easy)
        ->and($rows['2026-09-03']['prescribed_hard_minutes'])->toBe(0)
        ->and($rows['2026-09-06']['session_type'])->toBe(SessionType::Long)
        ->and($rows['2026-09-06']['prescribed_pace_band'])->toBe(PaceBand::Marathon)
        ->and($rows['2026-09-06']['prescribed_hard_minutes'])->toBeGreaterThan(0);
});

it('reserves unknown pinned quality without inventing a remaining hard-minute allowance', function (): void {
    $rows = periodizerRows('2026-08-10', '2026-10-05', [
        'sessionsPerWeek' => 6, 'projectedRaceSeconds' => 10_800.0, 'raceGoalTimeSec' => 10_800, 'raceDistanceM' => 42_195.0, 'volumeFloorKm' => 60.0, 'longRunBaselineKm' => 28.0, 'longRunCapKm' => 30.0, 'longRunProgressionCapKm' => 5.3, 'raceAmbitionState' => RaceAmbitionState::Unknown,
        'pinnedDates' => ['2026-08-18' => true],
        'fixedSessions' => ['2026-08-18' => ['session_type' => SessionType::Interval, 'prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null, 'hard_minutes' => null]],
    ]);

    expect($rows['2026-08-20']['session_type'])->toBe(SessionType::Easy)
        ->and($rows['2026-08-23']['prescribed_pace_band'])->toBeNull()
        ->and($rows['2026-08-23']['prescribed_hard_minutes'])->toBe(0);
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
    expect($steady)->toBe(2)
        ->and($behindGoal)->toBeLessThanOrEqual(2)
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

    periodizer()->regenerate($user);

    $raceRow = PlannedSession::query()->where('user_id', $user->id)
        ->where('date', $raceDate->toDateString())->firstOrFail();

    expect($raceRow->session_type)->toBe(SessionType::Race)
        ->and($raceRow->race_distance_m)->toBe(21_097);
});

it('stamps the race distance on the race day alone, and plans no race day once the athlete clears their race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(4),
        'distance_m' => 21_097,
        'completed_at' => null,
    ]);
    periodizer()->regenerate($user);

    $stamped = PlannedSession::query()->where('user_id', $user->id)
        ->whereNotNull('race_distance_m')->get();

    expect($stamped)->toHaveCount(1)
        ->and(nonNull($stamped->first())->session_type)->toBe(SessionType::Race);

    $race->update(['completed_at' => now()]);
    periodizer()->regenerate($user);

    expect(PlannedSession::query()->where('user_id', $user->id)->where('session_type', SessionType::Race)->exists())->toBeFalse()
        ->and(PlannedSession::query()->where('user_id', $user->id)->whereNotNull('race_distance_m')->exists())->toBeFalse();
});

it('leaves nothing behind when a near-term race shrinks the horizon, and refills it when the race is cleared', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    periodizer()->regenerate($user);
    $selfScaledEnd = PlannedSession::query()->where('user_id', $user->id)->max('date');
    $farFutureDate = Carbon::today()->addWeeks(10)->toDateString();
    expect(PlannedSession::query()->where('user_id', $user->id)->where('date', '>=', $farFutureDate)->exists())->toBeTrue();

    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(3),
        'distance_m' => 10_000,
        'completed_at' => null,
    ]);
    periodizer()->regenerate($user);

    $raceArcEnd = Carbon::today()->addWeeks(3)->endOfWeek(Carbon::SUNDAY)->toDateString();
    expect(PlannedSession::query()->where('user_id', $user->id)->max('date'))->toBe($raceArcEnd)
        // The shrink leaves no row from the longer arc it replaced.
        ->and(PlannedSession::query()->where('user_id', $user->id)->where('date', '>', $raceArcEnd)->exists())->toBeFalse()
        ->and(PlannedSession::query()->where('user_id', $user->id)->where('date', '>=', $farFutureDate)->exists())->toBeFalse();

    $race->update(['completed_at' => now()]);
    periodizer()->regenerate($user);

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

    periodizer()->regenerate($user, Carbon::today());

    $fresh = PlannedSession::query()->where('user_id', $user->id)->where('date', Carbon::today()->toDateString())->firstOrFail();
    expect($fresh->id)->not->toBe($today->id)
        ->and($fresh->clamped_km)->toBe(3.6);

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

    periodizer()->regenerate($user, Carbon::today());

    $fresh = PlannedSession::query()->where('user_id', $user->id)->where('date', Carbon::today()->toDateString())->firstOrFail();
    expect($fresh->id)->not->toBe($today->id)
        ->and($fresh->eased_pace_sec_per_km)->toBe(375)
        ->and($fresh->clamped_km)->toBeNull();

    // Unlike clamped_km, a pace ease never overrides the
    // session type it rides along on — EffectiveSession reads it back
    // against whatever type the regenerated row actually carries.
    $effective = EffectiveSession::of($fresh, 20.0);
    expect($effective->sessionType)->toBe($fresh->session_type)
        ->and($effective->coreKm)->toBe(20.0)
        ->and($effective->isPaceEased())->toBeTrue()
        ->and($effective->easedPaceSecPerKm)->toBe(375);
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

    periodizer()->regenerate($user, Carbon::today());

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

    periodizer()->regenerate($user, Carbon::today());

    $fresh = nonNull($scored->fresh());
    expect($fresh->status)->toBe(PlannedSessionStatus::Done)
        ->and($fresh->compliance_score)->toBe(104)
        ->and($fresh->session_type)->toBe(SessionType::Long);
});

it('plans the week around the day an eased tempo became, not the tempo it abandoned', function (bool $eased, bool $qualityPlanned): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    WeeklySnapshot::query()->where('user_id', $user->id)->update(['distance_km' => 60.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => '2026-08-08 07:00:00', 'distance' => 20_000]);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    PersonalRecord::factory()->for($user)->create(['category' => '10km', 'value_sec' => 2700, 'set_at' => Carbon::today()]);
    periodizer()->regenerate($user, Carbon::today());
    PlannedSession::query()->updateOrCreate(['user_id' => $user->id, 'date' => '2026-08-10'], [
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
        'clamped_km' => $eased ? 6.0 : null,
        'intent_verdict' => 'hit',
        'intent_evidence' => $eased
            ? ['advice_history' => 'shown', 'effective_type' => 'easy', 'eased_from' => 'tempo', 'concern' => 'mild', 'stimulus_family' => 'easy']
            : ['advice_history' => 'shown', 'effective_type' => 'tempo', 'concern' => 'none', 'quality_progression' => 'eligible'],
    ]);

    periodizer()->regenerate($user, Carbon::today());

    expect(PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-13')->firstOrFail()->prescribed_hard_minutes > 0)->toBe($qualityPlanned);
})->with([
    'eased tempo run easy' => [true, true],
    'tempo run without hard-minute measurement' => [false, false],
]);

it('persists an endurance tilt on the Build and Peak long runs, and nowhere outside them', function (): void {
    $rows = periodizerRows('2026-08-10', '2026-10-12', [
        'projectedRaceSeconds' => 3_338.0,
        'raceGoalTimeSec' => 3_338,
        'volumeFloorKm' => 30.0,
        'paces' => ['easy' => 409, 'marathon' => 362, 'threshold' => 314, 'interval' => 285],
        'longRunBaselineKm' => 15.0,
        'longRunCapKm' => 15.0,
        'longRunProgressionCapKm' => 5.3,
        'fallOffTilt' => FallOffTilt::Endurance,
        'raceAmbitionState' => RaceAmbitionState::Unsupported,
        'raceAmbitionGapPct' => 10.1,
        'raceSteppingStoneTimeSec' => 3_238,
        'timeTrialAimSec' => 1_607,
        'timeTrialEvidenceDates' => ['2026-07-20'],
    ]);

    $tilted = array_filter($rows, fn (array $row): bool => $row['fall_off_tilt'] !== null);
    $blockLongs = array_filter($rows, fn (array $row): bool => $row['session_type'] === SessionType::Long && in_array($row['phase'], [PlanPhase::Build, PlanPhase::Peak], true));

    expect($blockLongs)->not->toBeEmpty()
        ->and(array_all($blockLongs, fn (array $row): bool => $row['fall_off_tilt'] === FallOffTilt::Endurance))->toBeTrue()
        ->and(array_all($tilted, fn (array $row): bool => in_array($row['phase'], [PlanPhase::Build, PlanPhase::Peak], true)
            && in_array($row['session_type'], [SessionType::Long, SessionType::Tempo], true)))->toBeTrue();
});
