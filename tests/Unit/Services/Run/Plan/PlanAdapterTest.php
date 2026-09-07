<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\PlanAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function decide(
    ReadinessCeiling $ceiling = ReadinessCeiling::QualityOk,
    ?float $monotony = 1.2,
    ?float $strain = 400.0,
    ?float $ctl = 40.0,
    int $adherencePct = 100,
    int $raggedDays = 0,
    int $egregiousDays = 0,
    ?float $raceGapRatio = null,
): array {
    return PlanAdapter::decide($ceiling, $monotony, $strain, $ctl, $adherencePct, $raggedDays, $egregiousDays, $raceGapRatio);
}

it('leaves a healthy, fully adhered week alone', function (): void {
    expect(decide())->toBe([
        'reason' => AdaptationReason::Steady,
        'deload' => false,
        'quality_delta' => 0,
        'adherence_pct' => 100,
    ]);
});

it('deloads when readiness bottoms out at rest', function (): void {
    $decision = decide(ceiling: ReadinessCeiling::Rest);

    expect($decision['reason'])->toBe(AdaptationReason::LowReadiness)
        ->and($decision['deload'])->toBeTrue();
});

it('deloads at the monotony injury-risk threshold', function (): void {
    expect(decide(monotony: PlanAdapter::MONOTONY_DELOAD)['reason'])->toBe(AdaptationReason::HighMonotony)
        ->and(decide(monotony: PlanAdapter::MONOTONY_DELOAD - 0.01)['reason'])->toBe(AdaptationReason::Steady);
});

it('deloads when strain runs past what the athlete\'s fitness supports', function (): void {
    $decision = decide(strain: 40.0 * PlanAdapter::STRAIN_TO_CTL_DELOAD + 1, ctl: 40.0);

    expect($decision['reason'])->toBe(AdaptationReason::HighStrain)
        ->and($decision['deload'])->toBeTrue();
});

it('ignores the strain ratio below the CTL floor, where it is noise', function (): void {
    $ctl = PlanAdapter::MIN_CTL_FOR_STRAIN - 1;

    expect(decide(strain: $ctl * 100, ctl: $ctl)['reason'])->toBe(AdaptationReason::Steady);
});

it('treats a mostly missed week as a re-entry deload, not a catch-up', function (): void {
    $decision = decide(adherencePct: 20);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['deload'])->toBeTrue()
        ->and($decision['quality_delta'])->toBe(0)
        ->and($decision['adherence_pct'])->toBe(20);
});

it('drops a quality session when last week was run harder than it was written', function (): void {
    $decision = decide(raggedDays: PlanAdapter::RAGGED_DAYS_MIN);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1)
        ->and($decision['deload'])->toBeFalse();
});

it('reads a single ragged day as a bad morning, not as how the week was run', function (): void {
    expect(decide(raggedDays: PlanAdapter::RAGGED_DAYS_MIN - 1)['reason'])->toBe(AdaptationReason::Steady);
});

it('lets one day far enough past the line speak for the week on its own', function (): void {
    $decision = decide(raggedDays: 1, egregiousDays: 1);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1);
});

it('keeps a safety deload ahead of how the week was run', function (): void {
    expect(decide(adherencePct: 20, raggedDays: 5)['reason'])->toBe(AdaptationReason::MissedWeek);
});

it('backs off instead of adding work when an athlete behind their goal ran the week too hard', function (): void {
    $decision = decide(raggedDays: PlanAdapter::RAGGED_DAYS_MIN, raceGapRatio: 1.5);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1);
});

it('adds a quality session when the projection is behind the goal time', function (): void {
    $decision = decide(raceGapRatio: 1.08);

    expect($decision['reason'])->toBe(AdaptationReason::BehindRacePace)
        ->and($decision['quality_delta'])->toBe(1)
        ->and($decision['deload'])->toBeFalse();
});

it('drops a quality session when the projection is already inside the goal time', function (): void {
    $decision = decide(raceGapRatio: 0.9);

    expect($decision['reason'])->toBe(AdaptationReason::AheadOfRacePace)
        ->and($decision['quality_delta'])->toBe(-1);
});

it('holds steady inside the race-gap margin', function (): void {
    expect(decide(raceGapRatio: 1.0 + PlanAdapter::RACE_GAP_MARGIN)['reason'])->toBe(AdaptationReason::Steady)
        ->and(decide(raceGapRatio: 1.0 - PlanAdapter::RACE_GAP_MARGIN)['reason'])->toBe(AdaptationReason::Steady);
});

it('never lets chasing a goal time override a safety deload', function (): void {
    $decision = decide(ceiling: ReadinessCeiling::Rest, raceGapRatio: 1.5);

    expect($decision['reason'])->toBe(AdaptationReason::LowReadiness)
        ->and($decision['quality_delta'])->toBe(0);
});

it('tolerates unknown load numbers', function (): void {
    expect(decide(monotony: null, strain: null, ctl: null)['reason'])->toBe(AdaptationReason::Steady);
});

it('clamps the reported adherence into 0-100 percent', function (): void {
    expect(decide(adherencePct: 140)['adherence_pct'])->toBe(100)
        ->and(decide(adherencePct: -20)['adherence_pct'])->toBe(0);
});

it('reads last week\'s persisted compliance scores and the live signals to reach a verdict', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $weekStart = Carbon::parse('2026-08-10');

    // Every day already scored Missed (score 0) — the daily plan:score-compliance
    // pass would have written this before Monday's regeneration reads it.
    foreach (range(0, 4) as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->subWeek()->addDays($offset)->toDateString(),
            'phase' => PlanPhase::Build,
            'session_type' => SessionType::Easy,
            'status' => PlannedSessionStatus::Missed,
            'compliance_score' => 0,
        ]);
    }

    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'monotony' => 1.1, 'strain' => 300.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal',
    ]);

    $adapter = new PlanAdapter($trainingLoad, app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, $weekStart, Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['adherence_pct'])->toBe(0);

    Carbon::setTestNow();
});

it('averages last week\'s scores, capping an overreached day at 100 rather than letting it mask a miss', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $weekStart = Carbon::parse('2026-08-10');

    PlannedSession::factory()->for($user)->create([
        'date' => $weekStart->copy()->subWeek()->toDateString(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Overreached,
        'compliance_score' => 180,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => $weekStart->copy()->subWeek()->addDay()->toDateString(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => 0,
    ]);
    // Excluded: still unscored (safety net, not proof of anything) and skipped (excused).
    PlannedSession::factory()->for($user)->create([
        'date' => $weekStart->copy()->subWeek()->addDays(2)->toDateString(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Planned,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => $weekStart->copy()->subWeek()->addDays(3)->toDateString(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Skip,
        'skipped' => true,
    ]);

    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'monotony' => 1.1, 'strain' => 300.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal',
    ]);

    $adapter = new PlanAdapter($trainingLoad, app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, $weekStart, Carbon::parse('2026-08-10'), null);

    // (min(100,180) + 0) / 2 = 50, not (180+0)/2 = 90 — the overreached day is
    // capped before averaging, so it can't paper over the missed one.
    expect($decision['adherence_pct'])->toBe(50);

    Carbon::setTestNow();
});

it('reads perfect adherence when nothing from last week was scoreable yet', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'monotony' => 1.1, 'strain' => 100.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal',
    ]);

    $adapter = new PlanAdapter($trainingLoad, app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['adherence_pct'])->toBe(100)
        ->and($decision['reason'])->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('turns a race projection slower than the goal time into a behind-pace verdict', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'distance_m' => 21_097,
        'goal_time_sec' => 6000,
        'race_date' => '2026-11-01',
    ]);

    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'monotony' => 1.1, 'strain' => 100.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal',
    ]);
    $riegel = Mockery::mock(RiegelProjector::class);
    $riegel->shouldReceive('project')->andReturn([
        'predicted_sec' => 7200.0, 'low_sec' => 6800.0, 'high_sec' => 7600.0,
        'exponent' => 1.06, 'sample_size' => 3, 'confidence' => 'medium',
    ]);

    $adapter = new PlanAdapter($trainingLoad, $riegel);
    $decision = $adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), $race);

    expect($decision['reason'])->toBe(AdaptationReason::BehindRacePace)
        ->and($decision['quality_delta'])->toBe(1);

    Carbon::setTestNow();
});

it('ignores the race projection when the athlete has no usable PR to anchor it', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['goal_time_sec' => 6000, 'race_date' => '2026-11-01']);

    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'monotony' => 1.1, 'strain' => 100.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal',
    ]);
    $riegel = Mockery::mock(RiegelProjector::class);
    $riegel->shouldReceive('project')->andReturnNull();

    $adapter = new PlanAdapter($trainingLoad, $riegel);

    expect($adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), $race)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

/**
 * A run on $date owned by $user, carrying exactly the stream summary given.
 *
 * @param  array<string, mixed>  $streamSummary
 */
function planAdapterRunOn(User $user, string $date, array $streamSummary): void
{
    ActivityDetail::factory()
        ->for(Activity::factory()->for($user))
        ->create([
            'start_date_local' => Carbon::parse($date.' 06:00:00'),
            'stream_summary' => $streamSummary,
        ]);
}

/** A day last week that was prescribed and fully credited, so adherence stays out of the way. */
function planAdapterCreditedDay(User $user, string $date, SessionType $type): void
{
    PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'phase' => PlanPhase::Build,
        'session_type' => $type,
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 100,
    ]);
}

/** @param  array<string, mixed>  $load */
function planAdapterFor(array $load = ['monotony' => 1.1, 'strain' => 300.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal']): PlanAdapter
{
    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn($load);

    return new PlanAdapter($trainingLoad, app(RiegelProjector::class));
}

it('reads an easy day run above Z2 and a long day that decoupled as how the week was run', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterCreditedDay($user, '2026-08-05', SessionType::Long);

    planAdapterRunOn($user, '2026-08-03', ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 55, 'Z3' => 25]]);
    planAdapterRunOn($user, '2026-08-05', ['decoupling_pct' => PlanAdapter::HIGH_DECOUPLING + 2.5]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1)
        ->and($decision['adherence_pct'])->toBe(100);

    Carbon::setTestNow();
});

it('judges no day it cannot read: a run with no heart-rate stream is no signal, not a clean one', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterCreditedDay($user, '2026-08-05', SessionType::Long);

    planAdapterRunOn($user, '2026-08-03', []);
    planAdapterRunOn($user, '2026-08-05', []);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('counts a day once however many runs it holds', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);

    $hard = ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 50, 'Z3' => 40]];
    planAdapterRunOn($user, '2026-08-03', $hard);
    planAdapterRunOn($user, '2026-08-03', $hard);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('leaves a rest day unjudged, however it was run', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Rest);
    planAdapterCreditedDay($user, '2026-08-04', SessionType::Rest);

    $hard = ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 40, 'Z4' => 50], 'decoupling_pct' => 12.0];
    planAdapterRunOn($user, '2026-08-03', $hard);
    planAdapterRunOn($user, '2026-08-04', $hard);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('lets one long run that came apart speak for the week, where two milder days would be needed', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-05', SessionType::Long);
    planAdapterRunOn($user, '2026-08-05', ['decoupling_pct' => PlanAdapter::EGREGIOUS_DECOUPLING + 0.1]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1);

    Carbon::setTestNow();
});

it('holds one merely ragged day below the egregious line to the two-day bar', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-05', SessionType::Long);
    planAdapterRunOn($user, '2026-08-05', ['decoupling_pct' => PlanAdapter::EGREGIOUS_DECOUPLING - 0.1]);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});
