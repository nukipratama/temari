<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\PlanAdapter;
use App\Services\Run\Plan\SessionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function decide(
    ReadinessCeiling $ceiling = ReadinessCeiling::QualityOk,
    ?float $monotony = 1.2,
    ?float $strain = 400.0,
    ?float $ctl = 40.0,
    float $adherence = 1.0,
    ?float $raceGapRatio = null,
): array {
    return PlanAdapter::decide($ceiling, $monotony, $strain, $ctl, $adherence, $raceGapRatio);
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
    $decision = decide(adherence: 0.2);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['deload'])->toBeTrue()
        ->and($decision['quality_delta'])->toBe(0)
        ->and($decision['adherence_pct'])->toBe(20);
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
    expect(decide(adherence: 1.4)['adherence_pct'])->toBe(100)
        ->and(decide(adherence: -0.2)['adherence_pct'])->toBe(0);
});

it('reads last week\'s stored plan and the live signals to reach a verdict', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $weekStart = Carbon::parse('2026-08-10');

    foreach (range(0, 4) as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->subWeek()->addDays($offset)->toDateString(),
            'phase' => PlanPhase::Build,
            'session_type' => SessionType::Easy,
        ]);
    }

    $trainingLoad = Mockery::mock(TrainingLoad::class);
    $trainingLoad->shouldReceive('summary')->andReturn([
        'monotony' => 1.1, 'strain' => 300.0, 'ctl_42d' => 30.0, 'form' => 5.0, 'form_status' => 'optimal',
    ]);

    $adapter = new PlanAdapter(app(SessionMatcher::class), $trainingLoad, app(RiegelProjector::class));
    $decision = $adapter->forWeek($user, $weekStart, Carbon::parse('2026-08-10'), 20.0, null);

    expect($decision['reason'])->toBe(AdaptationReason::MissedWeek)
        ->and($decision['adherence_pct'])->toBe(0);

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

    $adapter = new PlanAdapter(app(SessionMatcher::class), $trainingLoad, $riegel);
    $decision = $adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), 20.0, $race);

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

    $adapter = new PlanAdapter(app(SessionMatcher::class), $trainingLoad, $riegel);

    expect($adapter->forWeek($user, Carbon::parse('2026-08-10'), Carbon::parse('2026-08-10'), 20.0, $race)['reason'])
        ->toBe(AdaptationReason::Steady);

    Carbon::setTestNow();
});
