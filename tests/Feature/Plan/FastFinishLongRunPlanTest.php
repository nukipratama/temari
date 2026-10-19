<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\FallOffTilt;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceAmbitionState;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\FastFinishLongRun;
use App\Services\Run\Plan\IntensityPrescription;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanInputs;
use App\Services\Run\Plan\RaceAmbitionAssessor;
use App\Services\Run\Plan\SegmentGenerator;
use Illuminate\Support\Carbon;

const FAST_FINISH_RACE_DAY = '2026-12-19';

const FAST_FINISH_PACES = ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270];

/** Goal and supported times: 5:00/km for the 10K, 4:36 for the 5K, 4:59 for the half, 5:10 for the marathon. */
const FAST_FINISH_TIMES = [5_000 => 1380, 10_000 => 3000, 21_097 => 6300, 42_195 => 13_080];

function fastFinishInputs(
    float $distanceM = 10_000.0,
    ?RaceAmbitionState $band = RaceAmbitionState::OnTrack,
    string $today = '2026-09-07',
    int $sessions = 4,
    float $longestSoFarKm = 18.0,
    ?string $lastFastFinish = null,
    ?int $supportedSec = null,
    ?int $supportedTenKSec = null,
): PlanInputs {
    $raceDate = Carbon::parse(FAST_FINISH_RACE_DAY);
    $goalTimeSec = FAST_FINISH_TIMES[(int) $distanceM];
    $supportedSec ??= $goalTimeSec;

    return new PlanInputs(
        userId: 1,
        today: Carbon::parse($today),
        seasonStart: $raceDate->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(19),
        seasonEnd: $raceDate,
        recovery: null,
        raceDate: $raceDate,
        raceDistanceM: $distanceM,
        sessionsPerWeek: $sessions,
        runDays: null,
        longRunDay: null,
        adaptation: ['reason' => AdaptationReason::Steady, 'deload' => false, 'quality_delta' => 0, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100],
        pinnedDates: [],
        settledDates: [],
        projectedRaceSeconds: (float) $goalTimeSec,
        raceGoalTimeSec: $goalTimeSec,
        paces: FAST_FINISH_PACES,
        longRunBaselineKm: 18.0,
        longRunCapKm: 32.0,
        raceAmbitionState: $band,
        raceSteppingStoneTimeSec: in_array($band, [RaceAmbitionState::Ambitious, RaceAmbitionState::Unsupported], true) ? RaceAmbitionAssessor::steppingStoneTimeSec($supportedSec) : null,
        raceSupportedTimeSec: $band === RaceAmbitionState::Unknown ? null : $supportedSec,
        longestRunSoFarKm: $longestSoFarKm,
        lastFastFinishDate: $lastFastFinish,
        supportedTenKTimeSec: $supportedTenKSec ?? FAST_FINISH_TIMES[10_000],
    );
}

/** @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}> */
function fastFinishPlan(PlanInputs $inputs): array
{
    return app(Periodizer::class)->rowsFor($inputs);
}

/**
 * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>  $rows
 * @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>
 */
function fastFinishes(array $rows): array
{
    return array_filter($rows, static fn (array $row): bool => $row['session_type'] === SessionType::Long
        && $row['prescribed_hard_minutes'] > 0
        && FastFinishLongRun::isFastFinish($row['prescription_race_context']));
}

/**
 * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>  $rows
 * @return array<string, float>
 */
function fastFinishLongKm(array $rows): array
{
    $km = [];
    foreach (array_filter($rows, static fn (array $row): bool => $row['session_type'] === SessionType::Long) as $date => $row) {
        $km[$date] = SegmentGenerator::coreKmFor(SessionType::Long, false, 18.0, $row['volume_multiplier'], 32.0, null, INF, $row['fall_off_tilt'] ?? null);
    }

    return $km;
}

it('shows two to three fast-finish long runs from mid-build through peak, none in a deload or taper week and none on a new longest distance', function (): void {
    $rows = fastFinishPlan(fastFinishInputs(today: '2026-10-05'));
    $finishes = fastFinishes($rows);
    $longKm = fastFinishLongKm($rows);
    $buildWeeks = array_values(array_unique(array_map(
        static fn (string $date): string => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
        array_keys(array_filter($rows, static fn (array $row): bool => $row['phase'] === PlanPhase::Build)),
    )));

    expect(count($finishes))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3);
    $previous = null;
    foreach ($finishes as $date => $row) {
        $earlierLongest = max([18.0, ...array_values(array_filter($longKm, static fn (string $other): bool => $other < $date, ARRAY_FILTER_USE_KEY))]);
        expect($row['phase'])->toBeIn([PlanPhase::Build, PlanPhase::Peak])
            ->and($longKm[$date])->toBeLessThanOrEqual($earlierLongest)
            ->and(Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString() >= $buildWeeks[intdiv(count($buildWeeks), 2)])->toBeTrue();
        if ($previous !== null) {
            expect((int) Carbon::parse($previous)->diffInWeeks(Carbon::parse($date)))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3);
        }
        $previous = $date;
    }
});

it('runs the last fifth of the long run at the race-specific pace, an easy block then the fast block', function (): void {
    $rows = fastFinishPlan(fastFinishInputs(today: '2026-10-05'));
    $date = nonNull(array_key_last(fastFinishes($rows)));
    $row = $rows[$date];
    $prescription = new IntensityPrescription($row['prescribed_hard_minutes'], $row['prescribed_pace_band'], $row['prescribed_pace_sec_per_km'], null, $row['prescription_race_context']);
    $km = fastFinishLongKm($rows)[$date];
    $segments = SegmentGenerator::forPrescription(SessionType::Long, $row['phase'], $km, FAST_FINISH_PACES, $prescription);

    expect(array_map(static fn ($segment): SegmentKey => $segment->key, $segments))->toBe([SegmentKey::Easy, SegmentKey::Main])
        ->and($segments[0]->paceLabel)->toBe(PaceBand::Easy)
        ->and($segments[1]->paceSecPerKm)->toBe(300)
        ->and($segments[1]->paceLabel)->toBe(PaceBand::Threshold)
        ->and($segments[1]->km / $km)->toBeGreaterThanOrEqual(0.15)->toBeLessThanOrEqual(0.25)
        ->and(SegmentGenerator::segmentSumKm($segments))->toBe(round($km, 1));
});

it('paces the fast finish at goal-pace work by band inside the window and at the supported race pace outside it', function (RaceAmbitionState $band, int $supportedSec, int $peakPace, int $buildPace): void {
    $rows = fastFinishPlan(fastFinishInputs(band: $band, today: '2026-10-05', longestSoFarKm: 40.0, supportedSec: $supportedSec));
    $paces = array_map(static fn (array $row): ?int => $row['prescribed_pace_sec_per_km'], fastFinishes($rows));

    expect(array_values(array_unique(array_map(static fn (string $date): string => $rows[$date]['phase']->value, array_keys($paces)))))->toBe([PlanPhase::Build->value, PlanPhase::Peak->value]);
    foreach ($paces as $date => $pace) {
        expect($pace)->toBe($rows[$date]['phase'] === PlanPhase::Peak ? $peakPace : $buildPace);
    }
})->with([
    'on track' => [RaceAmbitionState::OnTrack, 3000, 300, 300],
    'ambitious, stepping stone in the window' => [RaceAmbitionState::Ambitious, 3150, 306, 315],
    'low evidence, supported pace throughout' => [RaceAmbitionState::LowEvidence, 3100, 310, 310],
]);

it('paces a 5K goal\'s fast finish at its goal-pace work inside the window and at the supported 10K pace outside it', function (): void {
    $rows = fastFinishPlan(fastFinishInputs(5_000.0, today: '2026-10-05', longestSoFarKm: 40.0, supportedTenKSec: 2900));
    $byPhase = [];
    foreach (fastFinishes($rows) as $row) {
        $byPhase[$row['phase']->value][] = [$row['prescribed_pace_sec_per_km'], $row['prescribed_pace_band']];
    }

    expect(array_values(array_unique($byPhase[PlanPhase::Build->value] ?? [], SORT_REGULAR)))->toBe([[290, PaceBand::Threshold]])
        ->and(array_values(array_unique($byPhase[PlanPhase::Peak->value] ?? [], SORT_REGULAR)))->toBe([[276, PaceBand::Interval]]);
});

it('keeps a fast finish off the week the hard-day budget is already full', function (): void {
    $rows = fastFinishPlan(fastFinishInputs(today: '2026-10-05', sessions: 5));

    expect(fastFinishes($rows))->toBe([]);
});

it('never gives a two-run week a fast finish', function (): void {
    $rows = fastFinishPlan(fastFinishInputs(today: '2026-10-05', sessions: 2, longestSoFarKm: 40.0));

    expect(fastFinishes($rows))->toBe([]);
});

it('keeps the cadence across regenerations from the last fast finish run', function (): void {
    $fresh = array_keys(fastFinishes(fastFinishPlan(fastFinishInputs(today: '2026-10-05'))));
    $first = Carbon::parse($fresh[0]);
    $afterFirst = array_keys(fastFinishes(fastFinishPlan(fastFinishInputs(today: $first->copy()->addDay()->toDateString(), lastFastFinish: $first->toDateString()))));

    expect(Carbon::parse($afterFirst[0])->startOfWeek(Carbon::MONDAY)->diffInWeeks($first->copy()->startOfWeek(Carbon::MONDAY), absolute: true))->toBeGreaterThanOrEqual(2.0);
});

it('lets a long run the athlete has already run that far take a fast finish', function (): void {
    $default = array_keys(fastFinishes(fastFinishPlan(fastFinishInputs(today: '2026-10-05'))));
    $longer = array_keys(fastFinishes(fastFinishPlan(fastFinishInputs(today: '2026-10-05', longestSoFarKm: 40.0))));

    expect($longer[0] < $default[0])->toBeTrue();
});

it('gives 5K and half goals fast finishes and a marathon none, its race long keeping its marathon-pace block', function (): void {
    $marathon = fastFinishPlan(fastFinishInputs(42_195.0, today: '2026-10-05'));
    $raceLongs = array_filter($marathon, static fn (array $row): bool => $row['session_type'] === SessionType::Long && $row['prescribed_hard_minutes'] > 0);

    expect(fastFinishes(fastFinishPlan(fastFinishInputs(5_000.0, today: '2026-10-05'))))->not->toBe([])
        ->and(fastFinishes(fastFinishPlan(fastFinishInputs(21_097.5, today: '2026-10-05'))))->not->toBe([])
        ->and(fastFinishes($marathon))->toBe([])
        ->and($raceLongs)->not->toBe([])
        ->and(array_values(array_unique(array_map(static fn (array $row): ?PaceBand => $row['prescribed_pace_band'], $raceLongs), SORT_REGULAR)))->toBe([PaceBand::Marathon]);
});
