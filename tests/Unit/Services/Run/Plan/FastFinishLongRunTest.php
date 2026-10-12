<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PaceBand;
use App\Enums\RaceAmbitionState;
use App\Services\Run\Plan\FastFinishLongRun;
use App\Services\Run\Plan\GoalPaceWork;
use App\Services\Run\Plan\PlanInputs;
use Illuminate\Support\Carbon;

function fastFinishUnitInputs(?float $distanceM, ?RaceAmbitionState $band = RaceAmbitionState::LowEvidence, ?int $supportedSec = 3100, ?int $supportedTenKSec = null): PlanInputs
{
    return new PlanInputs(
        userId: 1,
        today: Carbon::parse('2026-11-02'),
        seasonStart: Carbon::parse('2026-08-03'),
        seasonEnd: Carbon::parse('2026-12-19'),
        recovery: null,
        raceDate: $distanceM === null ? null : Carbon::parse('2026-12-19'),
        raceDistanceM: $distanceM,
        sessionsPerWeek: 4,
        runDays: null,
        longRunDay: null,
        adaptation: ['reason' => AdaptationReason::Steady, 'deload' => false, 'quality_delta' => 0, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100],
        pinnedDates: [],
        settledDates: [],
        projectedRaceSeconds: null,
        raceAmbitionState: $band,
        raceSupportedTimeSec: $supportedSec,
        supportedTenKTimeSec: $supportedTenKSec,
    );
}

it('runs at the week\'s goal-pace work pace, else the supported race pace', function (): void {
    $goalPace = new GoalPaceWork(GoalPaceWork::KIND_10K, 10_000, 296, RaceAmbitionState::OnTrack);

    expect(FastFinishLongRun::forWeek(fastFinishUnitInputs(10_000.0), $goalPace)?->paceSecPerKm)->toBe(296)
        ->and(FastFinishLongRun::forWeek(fastFinishUnitInputs(10_000.0), null)?->paceSecPerKm)->toBe(310);
});

it('runs a 5K goal\'s fast finish without goal-pace work at the supported 10K pace, in the threshold band', function (): void {
    $supported = FastFinishLongRun::forWeek(fastFinishUnitInputs(5_000.0, RaceAmbitionState::OnTrack, 1400, 2900), null);
    $goalPace = FastFinishLongRun::forWeek(fastFinishUnitInputs(5_000.0, RaceAmbitionState::OnTrack, 1400, 2900), new GoalPaceWork(GoalPaceWork::KIND_5K, 5_000, 276, RaceAmbitionState::OnTrack));

    expect([$supported?->paceSecPerKm, $supported?->prescription(24.0)->paceBand])->toBe([290, PaceBand::Threshold])
        ->and([$goalPace?->paceSecPerKm, $goalPace?->prescription(24.0)->paceBand])->toBe([276, PaceBand::Interval])
        ->and(FastFinishLongRun::forWeek(fastFinishUnitInputs(5_000.0, RaceAmbitionState::OnTrack, 1400), null))->toBeNull();
});

it('exists only for a 5K-to-half race with a pace to run', function (?float $distanceM, ?RaceAmbitionState $band, ?int $supportedSec, bool $exists, ?int $supportedTenKSec = null): void {
    expect(FastFinishLongRun::forWeek(fastFinishUnitInputs($distanceM, $band, $supportedSec, $supportedTenKSec), null) !== null)->toBe($exists);
})->with([
    '5K' => [5_000.0, RaceAmbitionState::OnTrack, 1400, true, 2900],
    'half' => [21_097.5, RaceAmbitionState::OnTrack, 6300, true],
    'marathon' => [42_195.0, RaceAmbitionState::OnTrack, 13_000, false],
    'ultra' => [50_000.0, RaceAmbitionState::OnTrack, 18_000, false],
    'no race' => [null, null, null, false],
    'unknown band' => [10_000.0, RaceAmbitionState::Unknown, 3100, false],
    'no supported time' => [10_000.0, RaceAmbitionState::OnTrack, null, false],
]);

it('sizes the fast block at a fifth of the long run at its pace, in its pace\'s band', function (): void {
    $tenK = new FastFinishLongRun(GoalPaceWork::KIND_10K, 10_000, 300, GoalPaceWork::KIND_10K)->prescription(24.0);
    $fiveK = new FastFinishLongRun(GoalPaceWork::KIND_5K, 5_000, 276, GoalPaceWork::KIND_5K)->prescription(24.0);

    expect([$tenK->hardMinutes, $tenK->paceBand, $tenK->paceSecPerKm, $tenK->raceContext])->toBe([24, PaceBand::Threshold, 300, ['kind' => 'fast_finish', 'race_kind' => '10k', 'distance_m' => 10_000]])
        ->and([$fiveK->hardMinutes, $fiveK->paceBand])->toBe([22, PaceBand::Interval])
        ->and(FastFinishLongRun::isFastFinish($tenK->raceContext))->toBeTrue()
        ->and(FastFinishLongRun::isFastFinish(['kind' => '10k', 'band' => 'on_track']))->toBeFalse()
        ->and(FastFinishLongRun::isFastFinish(null))->toBeFalse();
});
