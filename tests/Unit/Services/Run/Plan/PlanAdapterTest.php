<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\IngestState;
use App\Enums\PaceBand;
use App\Enums\RaceAmbitionState;
use App\Services\Run\Plan\RaceAmbition;
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
use App\Services\Run\Story\BriefingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function decide(
    int $adherencePct = 100,
    int $stimulusAdherencePct = 100,
    int $stimulusMisses = 0,
    int $stimulusMissesInWindow = 0,
    int $raggedDays = 0,
    int $egregiousEasyDays = 0,
    ?float $raceGapRatio = null,
): array {
    return PlanAdapter::decide($adherencePct, $stimulusAdherencePct, $stimulusMisses, $stimulusMissesInWindow ?: $stimulusMisses, $raggedDays, $egregiousEasyDays, $raceGapRatio);
}

it('leaves a healthy, fully adhered week alone', function (): void {
    expect(decide())->toBe([
        'reason' => AdaptationReason::Steady,
        'deload' => false,
        'quality_delta' => 0,
        'adherence_pct' => 100,
        'stimulus_adherence_pct' => 100,
    ]);
});

it('treats a mostly missed week as a re-entry deload, not a catch-up', function (): void {
    $decision = decide(adherencePct: 20);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['deload'])->toBeTrue()
        ->and($decision['quality_delta'])->toBe(0)
        ->and($decision['adherence_pct'])->toBe(20);
});

it('holds quality when one key stimulus is missed, even when the race projection is behind', function (): void {
    $decision = decide(stimulusAdherencePct: 0, stimulusMisses: 1, raceGapRatio: 1.08);

    expect($decision['reason'])->toBe(AdaptationReason::MissedStimulus)
        ->and($decision['quality_delta'])->toBe(0)
        ->and($decision['deload'])->toBeFalse()
        ->and($decision['stimulus_adherence_pct'])->toBe(0);
});

it('drops one quality slot when repeated key stimuli are missed', function (): void {
    $decision = decide(stimulusAdherencePct: 33, stimulusMisses: 2);

    expect($decision['reason'])->toBe(AdaptationReason::MissedStimulus)
        ->and($decision['quality_delta'])->toBe(-1)
        ->and($decision['deload'])->toBeFalse();
});

it('drops quality when misses repeat across the settled three-week window', function (): void {
    $decision = decide(stimulusAdherencePct: 50, stimulusMisses: 1, stimulusMissesInWindow: 2);

    expect($decision['reason'])->toBe(AdaptationReason::MissedStimulus)
        ->and($decision['quality_delta'])->toBe(-1);
});

it('drops one quality slot when the week has key work but none of it landed', function (): void {
    $decision = decide(stimulusAdherencePct: 0, stimulusMisses: 2);

    expect($decision['reason'])->toBe(AdaptationReason::MissedStimulus)
        ->and($decision['quality_delta'])->toBe(-1);
});

it('does not penalize stimulus adherence when there is no judgeable key work', function (): void {
    expect(decide(stimulusAdherencePct: 100, stimulusMisses: 0)['reason'])
        ->toBe(AdaptationReason::Steady);
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

it('lets one easy day far enough above Z2 speak for the week on its own', function (): void {
    $decision = decide(raggedDays: 1, egregiousEasyDays: 1);

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

it('adds no quality session when the projection is behind the goal time', function (): void {
    $decision = decide(raceGapRatio: 1.08);

    expect($decision['reason'])->toBe(AdaptationReason::Steady)
        ->and($decision['quality_delta'])->toBe(0)
        ->and($decision['deload'])->toBeFalse();
});

it('lets a settled current-week hit release a previous-week quality hold before race feedback', function (): void {
    $decision = decide(
        stimulusAdherencePct: 100,
        stimulusMisses: 0,
        stimulusMissesInWindow: 0,
        raceGapRatio: 1.08,
    );

    expect($decision['reason'])->toBe(AdaptationReason::Steady)
        ->and($decision['quality_delta'])->toBe(0);
});

it('keeps the quality count when the projection is already inside the goal time', function (): void {
    $decision = decide(raceGapRatio: 0.9);

    expect($decision['reason'])->toBe(AdaptationReason::AheadOfRacePace)
        ->and($decision['quality_delta'])->toBe(0);
});

it('still names the ahead-of-pace reason even though the plan does not change', function (): void {
    $ahead = decide(raceGapRatio: 0.9)['reason'];

    expect($ahead->headline())->toBe('ahead of pace')
        ->and($ahead->detail(100))->toContain('not a reason to back off');
});

// Being ahead is fitness feedback, not a red flag: only the fatigue-driven
// reasons still cost a quality session.
it('still drops a quality session through the fatigue trigger even while ahead of race pace', function (): void {
    $decision = decide(raggedDays: PlanAdapter::RAGGED_DAYS_MIN, raceGapRatio: 0.9);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1);
});

it('holds steady inside the race-gap margin', function (): void {
    expect(decide(raceGapRatio: 1.0 + PlanAdapter::RACE_GAP_MARGIN)['reason'])->toBe(AdaptationReason::Steady)
        ->and(decide(raceGapRatio: 1.0 - PlanAdapter::RACE_GAP_MARGIN)['reason'])->toBe(AdaptationReason::Steady);
});

it('never lets chasing a goal time override a safety deload', function (): void {
    $decision = decide(adherencePct: 20, raceGapRatio: 1.5);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['quality_delta'])->toBe(0);
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
            'distance_score' => 0,
        ]);
    }

    $adapter = new PlanAdapter(app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, $weekStart, Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['adherence_pct'])->toBe(0);

    Carbon::setTestNow();
});

it('reads adherence on distance alone, so a week of intent misses at full distance is not a missed week', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $weekStart = Carbon::parse('2026-08-10');

    foreach (range(0, 4) as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->subWeek()->addDays($offset)->toDateString(),
            'phase' => PlanPhase::Build,
            'session_type' => SessionType::Tempo,
            'status' => PlannedSessionStatus::Partial,
            'compliance_score' => 84,
            'distance_score' => 100,
        ]);
    }

    $decision = new PlanAdapter(app(RiegelProjector::class))->forWeek($user, $weekStart, Carbon::parse('2026-08-10'), null);

    expect($decision['adherence_pct'])->toBe(100)
        ->and($decision['reason'])->not->toBe(AdaptationReason::MissedWeek);

    Carbon::setTestNow();
});

it('carries a full-distance key-session intent miss separately from volume adherence', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-03',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'compliance_score' => 84,
        'distance_score' => 100,
        'intent_verdict' => 'missed',
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
    ]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['adherence_pct'])->toBe(100)
        ->and($decision['stimulus_adherence_pct'])->toBe(0)
        ->and($decision['reason'])->toBe(AdaptationReason::MissedStimulus)
        ->and($decision['quality_delta'])->toBe(0);

    Carbon::setTestNow();
});

it('uses a settled current-week miss without judging today before the day closes', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();

    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-10',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'distance_score' => 100,
        'intent_verdict' => 'missed',
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-12',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'distance_score' => 100,
        'intent_verdict' => 'missed',
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
    ]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::today(), null);

    expect($decision['stimulus_adherence_pct'])->toBe(0)
        ->and($decision['reason'])->toBe(AdaptationReason::MissedStimulus);

    Carbon::setTestNow();
});

it('does not adapt quality from missed intent without shown-advice history while retaining distance credit', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-03',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'compliance_score' => 84,
        'distance_score' => 100,
        'intent_verdict' => 'missed',
        'intent_evidence' => ['advice_history' => 'unknown'],
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-04',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'compliance_score' => 84,
        'distance_score' => 100,
        'intent_verdict' => 'missed',
    ]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::today(), null);

    expect($decision['adherence_pct'])->toBe(100)
        ->and($decision['stimulus_adherence_pct'])->toBe(100)
        ->and($decision['reason'])->toBe(AdaptationReason::Steady)
        ->and($decision['quality_delta'])->toBe(0);

    Carbon::setTestNow();
});

it('does not treat an explicit key-session skip as a missed stimulus', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-03',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Skip,
        'skipped' => true,
    ]);

    $decision = planAdapterFor()->forWeek($user, Carbon::today(), Carbon::today(), null);

    expect($decision['stimulus_adherence_pct'])->toBe(100)
        ->and($decision['reason'])->toBe(AdaptationReason::Steady);

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
        'distance_score' => 180,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => $weekStart->copy()->subWeek()->addDay()->toDateString(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => 0,
        'distance_score' => 0,
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

    $adapter = new PlanAdapter(app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, $weekStart, Carbon::parse('2026-08-10'), null);

    // (min(100,180) + 0) / 2 = 50, not (180+0)/2 = 90 — the overreached day is
    // capped before averaging, so it can't paper over the missed one.
    expect($decision['adherence_pct'])->toBe(50);

    Carbon::setTestNow();
});

it('reads perfect adherence when nothing from last week was scoreable yet', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    $adapter = new PlanAdapter(app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['adherence_pct'])->toBe(100)
        ->and($decision['reason'])->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('adds no work when the race projection is slower than the goal time', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'distance_m' => 21_097,
        'goal_time_sec' => 6000,
        'race_date' => '2026-11-01',
    ]);

    $riegel = Mockery::mock(RiegelProjector::class);
    $riegel->shouldReceive('project')->andReturn([
        'predicted_sec' => 7200.0, 'low_sec' => 6800.0, 'high_sec' => 7600.0,
        'exponent' => 1.06, 'sample_size' => 3, 'confidence' => 'medium',
    ]);

    $adapter = new PlanAdapter($riegel);
    $decision = $adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), $race);

    expect($decision['reason'])->toBe(AdaptationReason::Steady)
        ->and($decision['quality_delta'])->toBe(0);

    Carbon::setTestNow();
});

it('does not chase an unsupported race ambition with extra quality', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'distance_m' => 10_000,
        'goal_time_sec' => 3000,
        'race_date' => '2026-09-07',
    ]);

    $riegel = Mockery::mock(RiegelProjector::class);
    $riegel->shouldReceive('project')->andReturn([
        'predicted_sec' => 4200.0, 'low_sec' => 4000.0, 'high_sec' => 4400.0,
        'exponent' => 1.06, 'sample_size' => 3, 'confidence' => 'medium',
    ]);
    $unsupported = new RaceAmbition(RaceAmbitionState::Unsupported, 3000, 300, 4200, 420, 28.6, 'confirmed');

    $adapter = new PlanAdapter($riegel);
    $decision = $adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), $race, $unsupported);

    expect($decision['reason'])->toBe(AdaptationReason::Steady)
        ->and($decision['quality_delta'])->toBe(0);

    Carbon::setTestNow();
});

it('ignores the race projection when the athlete has no usable PR to anchor it', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['goal_time_sec' => 6000, 'race_date' => '2026-11-01']);

    $riegel = Mockery::mock(RiegelProjector::class);
    $riegel->shouldReceive('project')->andReturnNull();

    $adapter = new PlanAdapter($riegel);

    expect($adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), $race)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

/**
 * A run on $date owned by $user, carrying exactly the stream summary given.
 *
 * @param  array<string, mixed>  $streamSummary
 */
function planAdapterRunOn(User $user, string $date, array $streamSummary, array $attributes = []): void
{
    ActivityDetail::factory()
        ->for(Activity::factory()->for($user))
        ->create([
            'start_date_local' => Carbon::parse($date.' 06:00:00'),
            'stream_summary' => $streamSummary,
            ...$attributes,
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
        'distance_score' => 100,
    ]);
}

function planAdapterFor(): PlanAdapter
{
    return new PlanAdapter(app(RiegelProjector::class));
}

it('reads two easy days run over the heart-rate cap as how the week was run', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterCreditedDay($user, '2026-08-05', SessionType::Easy);

    planAdapterRunOn($user, '2026-08-03', ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 720], ['moving_time' => 3000]);
    planAdapterRunOn($user, '2026-08-05', ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 720], ['moving_time' => 3000]);

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

    $hard = ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 720];
    planAdapterRunOn($user, '2026-08-03', $hard, ['moving_time' => 3000]);
    planAdapterRunOn($user, '2026-08-03', $hard, ['moving_time' => 3000]);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('leaves a rest day unjudged, however it was run', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Rest);
    planAdapterCreditedDay($user, '2026-08-04', SessionType::Rest);

    $hard = ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 2400, 'decoupling_pct' => 12.0];
    planAdapterRunOn($user, '2026-08-03', $hard, ['moving_time' => 3000]);
    planAdapterRunOn($user, '2026-08-04', $hard, ['moving_time' => 3000]);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});

it('keeps next week\'s quality after a hot long run and a threshold session that both decoupled past 15%', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-05', SessionType::Long);
    planAdapterCreditedDay($user, '2026-08-07', SessionType::Tempo);
    $drift = [
        'drift_metric_version' => 2,
        'steady_effort_decoupling_pct' => 20.0,
    ];
    planAdapterRunOn($user, '2026-08-05', $drift, ['weather_temp_c' => 33]);
    planAdapterRunOn($user, '2026-08-07', $drift);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe(AdaptationReason::Steady)
        ->and($decision['quality_delta'])->toBe(0);

    Carbon::setTestNow();
});

it('still lets one easy day far over the heart-rate cap speak for the week', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterRunOn($user, '2026-08-03', ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 1500], ['moving_time' => 3000]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe(AdaptationReason::RanTooHard)
        ->and($decision['quality_delta'])->toBe(-1);

    Carbon::setTestNow();
});


it('reads a long run with no marathon-pace block by the same cap, and leaves a marathon-pace long run to its block', function (int $hardMinutes, ?PaceBand $band, AdaptationReason $expected): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterRunOn($user, '2026-08-03', ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 720], ['moving_time' => 3000]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-07',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Long,
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 100,
        'distance_score' => 100,
        'prescribed_hard_minutes' => $hardMinutes,
        'prescribed_pace_band' => $band,
    ]);
    planAdapterRunOn($user, '2026-08-07', ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 1200], ['moving_time' => 6000]);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])->toBe($expected);

    Carbon::setTestNow();
})->with([
    'easy long run' => [0, null, AdaptationReason::RanTooHard],
    'marathon-pace long run' => [30, PaceBand::Marathon, AdaptationReason::Steady],
]);

it('lets one easy-effort day speak for the week only past thirty minutes, or two fifths of a run under 75 minutes', function (int $movingSec, int $overSec, AdaptationReason $expected): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Long);
    planAdapterRunOn($user, '2026-08-03', ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => $overSec], ['moving_time' => $movingSec]);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])->toBe($expected);

    Carbon::setTestNow();
})->with([
    '2 h, exactly 30 min over' => [7200, 1800, AdaptationReason::Steady],
    '2 h, past 30 min over' => [7200, 1801, AdaptationReason::RanTooHard],
    '50 min, exactly two fifths over' => [3000, 1200, AdaptationReason::Steady],
    '50 min, past two fifths over' => [3000, 1201, AdaptationReason::RanTooHard],
]);

it('leaves an eased tempo that was run easy out of stimulus adherence', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();

    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-03',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Partial,
        'compliance_score' => 84,
        'distance_score' => 100,
        'intent_verdict' => 'missed',
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-05',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'clamped_km' => 6.0,
        'compliance_score' => 100,
        'distance_score' => 100,
        'intent_verdict' => 'hit',
        'intent_evidence' => ['advice_history' => 'shown', 'effective_type' => 'easy', 'eased_from' => 'tempo', 'concern' => 'mild', 'stimulus_family' => 'easy'],
    ]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::today(), null);

    expect($decision['stimulus_adherence_pct'])->toBe(0);

    Carbon::setTestNow();
});

it('judges how last week was run against the effective advice, not the stored session type', function (bool $eased, AdaptationReason $expected): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $hard = ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 720];

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterRunOn($user, '2026-08-03', $hard, ['moving_time' => 3000]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-05',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'clamped_km' => $eased ? 6.0 : null,
        'compliance_score' => 100,
        'distance_score' => 100,
    ]);
    planAdapterRunOn($user, '2026-08-05', $hard, ['moving_time' => 3000]);

    $decision = planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null);

    expect($decision['reason'])->toBe($expected);

    Carbon::setTestNow();
})->with([
    'tempo eased to easy then run hard' => [true, AdaptationReason::RanTooHard],
    'tempo run as prescribed' => [false, AdaptationReason::Steady],
]);

it('reads the shown effective type over the stored one when the advice history recorded it', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $hard = ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 720];

    planAdapterCreditedDay($user, '2026-08-03', SessionType::Easy);
    planAdapterRunOn($user, '2026-08-03', $hard, ['moving_time' => 3000]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-05',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Overreached,
        'compliance_score' => 100,
        'distance_score' => 100,
        'intent_verdict' => 'too_hard',
        'intent_evidence' => ['advice_history' => 'shown', 'effective_type' => 'easy', 'eased_from' => 'tempo', 'concern' => 'mild', 'original_completed' => 'controlled'],
    ]);
    planAdapterRunOn($user, '2026-08-05', $hard, ['moving_time' => 3000]);

    expect(planAdapterFor()->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), null)['reason'])
        ->toBe(AdaptationReason::RanTooHard);

    Carbon::setTestNow();
});

/** A run on $daysFromStart days after $start carrying $trimp; a pending one still awaits hydration. */
function planAdapterLoadRun(User $user, Carbon $start, int $daysFromStart, ?float $trimp, bool $pending = false): void
{
    $activity = $pending ? Activity::factory()->for($user)->stub()->create() : Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $start->copy()->addDays($daysFromStart)->setTime(6, 0),
        'trimp_edwards' => $pending ? null : $trimp,
        'distance' => 6000.0,
    ]);
}

/**
 * @param  list<array{0: int, 1: ?float, 2?: bool}>  $runs  days from $start, trimp, pending
 */
function planAdapterLoadRuns(User $user, Carbon $start, array $runs): void
{
    $now = now()->toDateTimeString();
    Activity::query()->insert(array_map(fn (array $run): array => [
        ...(($run[2] ?? false) ? Activity::factory()->for($user)->stub() : Activity::factory()->for($user)->analyzed())->make()->getAttributes(),
        'created_at' => $now,
        'updated_at' => $now,
    ], $runs));
    $activityIds = Activity::query()->withStubs()->where('user_id', $user->id)->orderByDesc('id')->limit(count($runs))->pluck('id')->reverse()->values()->all();

    ActivityDetail::query()->insert(array_map(fn (array $run, int $activityId): array => [
        ...ActivityDetail::factory()->make([
            'activity_id' => $activityId,
            'start_date_local' => $start->copy()->addDays($run[0])->setTime(6, 0),
            'trimp_edwards' => ($run[2] ?? false) ? null : $run[1],
            'distance' => 6000.0,
        ])->getAttributes(),
        'created_at' => $now,
        'updated_at' => $now,
    ], $runs, $activityIds));
}

it('neither rests nor deloads a steady two-run beginner after one longer easy run', function (): void {
    $user = User::factory()->create();
    $start = Carbon::parse('2026-05-04');
    $runs = [];
    for ($week = 0; $week < 16; $week++) {
        $runs[] = [$week * 7 + 1, 60.0];
        $runs[] = [$week * 7 + 4, 60.0];
    }
    $runs[] = [16 * 7 - 1, 130.0];
    planAdapterLoadRuns($user, $start, $runs);
    $monday = $start->copy()->addWeeks(16);
    Carbon::setTestNow($monday->copy()->setTime(8, 0));

    $ceiling = BriefingContext::forUser($user, $monday, app(TrainingLoad::class)->summary($user, $monday))->readinessCeiling;
    $decision = app(PlanAdapter::class)->forWeek($user, $monday, $monday, null);

    expect($ceiling)->not->toBe(ReadinessCeiling::Rest->value)
        ->and($decision['deload'])->toBeFalse();

    Carbon::setTestNow();
});

it('neither rests nor deloads a steady four-run athlete in the six weeks after heart rate starts', function (): void {
    $user = User::factory()->create();
    $hrStart = Carbon::parse('2026-06-01');
    $runs = [];
    for ($day = -84; $day < 0; $day++) {
        if (in_array(($day + 84) % 7, [0, 2, 4, 6], true)) {
            $runs[] = [$day, null];
        }
    }
    for ($day = 0; $day < 42; $day++) {
        if (in_array($day % 7, [0, 2, 4, 6], true)) {
            $runs[] = [$day, 148.0];
        }
    }
    planAdapterLoadRuns($user, $hrStart, $runs);

    for ($week = 1; $week <= 6; $week++) {
        $monday = $hrStart->copy()->addWeeks($week);
        Carbon::setTestNow($monday->copy()->setTime(8, 0));
        $load = app(TrainingLoad::class)->summary($user, $monday);

        expect(BriefingContext::forUser($user, $monday, $load)->readinessCeiling)->not->toBe(ReadinessCeiling::Rest->value)
            ->and(app(PlanAdapter::class)->forWeek($user, $monday, $monday, null)['deload'])->toBeFalse();
    }

    Carbon::setTestNow();
});

it('stores no low-readiness deload for a race season opened mid-backfill, and reads the full series once it lands', function (): void {
    $user = User::factory()->create();
    $start = Carbon::parse('2026-05-04');
    $runDays = [];
    for ($week = 0; $week < 16; $week++) {
        foreach ([0, 2, 4, 6] as $offset) {
            $runDays[] = $week * 7 + $offset;
        }
    }
    $analysedFrom = (int) floor(count($runDays) * 0.75);
    planAdapterLoadRuns($user, $start, array_map(fn (int $index, int $day): array => [$day, 148.0, $index < $analysedFrom], array_keys($runDays), $runDays));
    $monday = $start->copy()->addWeeks(16);
    Carbon::setTestNow($monday->copy()->setTime(8, 0));

    $midBackfill = app(PlanAdapter::class)->forWeek($user, $monday, $monday, null);
    Activity::query()->withStubs()->where('user_id', $user->id)->whereNull('analyzed_at')->each(function (Activity $activity): void {
        $activity->forceFill(['analyzed_at' => now(), 'ingest_state' => IngestState::Detailed])->save();
        $activity->detail()->update(['trimp_edwards' => 148.0]);
    });
    TrainingLoad::clearSummaryCache($user);
    $fullHistory = app(TrainingLoad::class)->summary($user, $monday);

    expect($midBackfill['reason'])->not->toBe(AdaptationReason::LowReadiness)
        ->and($midBackfill['deload'])->toBeFalse()
        ->and($fullHistory['form_status'])->not->toBeNull()
        ->and($fullHistory['ctl_42d'])->toBeGreaterThan(70.0)
        ->and(app(PlanAdapter::class)->forWeek($user, $monday, $monday, null)['deload'])->toBeFalse();

    Carbon::setTestNow();
});

it('adapts a steady plan-shaped six-session week and a steady daily runner as steady', function (array $runDays, float $trimp): void {
    $user = User::factory()->create();
    $start = Carbon::parse('2026-05-04');
    $runs = [];
    for ($week = 0; $week < 12; $week++) {
        foreach ($runDays as $offset => $multiple) {
            $runs[] = [$week * 7 + $offset, $trimp * $multiple];
        }
    }
    planAdapterLoadRuns($user, $start, $runs);
    $monday = $start->copy()->addWeeks(12);
    Carbon::setTestNow($monday->copy()->setTime(8, 0));

    expect(app(PlanAdapter::class)->forWeek($user, $monday, $monday, null)['reason'])->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
})->with([
    'six sessions, rest on Monday, long run on Sunday' => [[1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 2.0], 70.0],
    'daily runner' => [[0 => 1.0, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 1.0], 60.0],
]);

it('handles a return after a two-week gap once, as a re-entry, not again as strain', function (): void {
    $user = User::factory()->create();
    $start = Carbon::parse('2026-05-04');
    $runs = [];
    for ($week = 0; $week < 10; $week++) {
        foreach ([0, 2, 4, 6] as $offset) {
            $runs[] = [$week * 7 + $offset, 90.0];
        }
    }
    planAdapterLoadRuns($user, $start, $runs);
    $gapStart = $start->copy()->addWeeks(10);
    foreach ([0, 2, 4, 6] as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => $gapStart->copy()->addWeek()->addDays($offset)->toDateString(),
            'phase' => PlanPhase::Build,
            'session_type' => SessionType::Easy,
            'status' => PlannedSessionStatus::Missed,
            'compliance_score' => 0,
            'distance_score' => 0,
        ]);
    }
    $returnWeek = $gapStart->copy()->addWeeks(2);
    foreach ([1, 2, 4, 6] as $offset) {
        planAdapterLoadRun($user, $returnWeek, $offset, 150.0);
        planAdapterCreditedDay($user, $returnWeek->copy()->addDays($offset)->toDateString(), SessionType::Easy);
    }
    $afterReturn = $returnWeek->copy()->addWeek();

    Carbon::setTestNow($returnWeek->copy()->setTime(8, 0));
    $onReturn = app(PlanAdapter::class)->forWeek($user, $returnWeek, $returnWeek, null);
    Carbon::setTestNow($afterReturn->copy()->setTime(8, 0));
    $weekAfter = app(PlanAdapter::class)->forWeek($user, $afterReturn, $afterReturn, null);

    expect($onReturn['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($weekAfter['reason'])->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});
