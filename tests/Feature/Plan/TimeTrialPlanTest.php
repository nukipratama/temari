<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceAmbitionState;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\GoalPaceWork;
use App\Services\Run\Plan\IntensityPrescription;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanInputs;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TimeTrial;
use App\Services\Run\Plan\TimeTrialSchedule;
use Illuminate\Support\Carbon;

const TRIAL_RACE_DAY = '2026-12-19';

const TRIAL_PACES = ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270];

/**
 * @param  list<array{date: string, retry: bool, skipped: bool}>  $trials
 */
function trialPlanInputs(
    ?float $raceDistanceM,
    string $today = '2026-10-05',
    ?int $aimSec = 1_400,
    bool $deload = false,
    array $trials = [],
    ?RaceAmbitionState $band = null,
): PlanInputs {
    $raceDate = Carbon::parse(TRIAL_RACE_DAY);
    $goalTimeSec = $raceDistanceM === null ? null : (int) round($raceDistanceM * 0.3);

    return new PlanInputs(
        userId: 1,
        today: Carbon::parse($today),
        seasonStart: $raceDistanceM === null ? Carbon::parse('2026-10-05') : $raceDate->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(19),
        seasonEnd: $raceDistanceM === null ? Carbon::parse('2026-12-27') : $raceDate,
        recovery: null,
        raceDate: $raceDistanceM === null ? null : $raceDate,
        raceDistanceM: $raceDistanceM,
        sessionsPerWeek: 4,
        runDays: null,
        longRunDay: null,
        adaptation: ['reason' => AdaptationReason::Steady, 'deload' => $deload, 'quality_delta' => 0, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100],
        pinnedDates: [],
        settledDates: [],
        projectedRaceSeconds: $goalTimeSec === null ? null : (float) $goalTimeSec,
        raceGoalTimeSec: $goalTimeSec,
        paces: TRIAL_PACES,
        longRunBaselineKm: 18.0,
        longRunCapKm: 32.0,
        raceAmbitionState: $band,
        raceAmbitionGapPct: $band === null ? null : 0.0,
        timeTrialAimSec: $aimSec,
        timeTrials: $trials,
    );
}

/** @return array<string, array<string, mixed>> */
function trialPlan(PlanInputs $inputs): array
{
    return app(Periodizer::class)->rowsFor($inputs);
}

/**
 * @param  array<string, array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function trialRows(array $rows): array
{
    return array_filter($rows, static fn (array $row): bool => TimeTrial::isTrial($row['prescription_race_context']));
}

/** @param  array<string, array<string, mixed>>  $rows */
function trialWeeksOf(array $rows): array
{
    return array_values(array_unique(array_map(
        static fn (string $date): string => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
        array_keys(trialRows($rows)),
    )));
}

/**
 * Hard days per week: quality sessions with work, and a hard long run.
 *
 * @param  array<string, array<string, mixed>>  $rows
 * @return array<string, int>
 */
function hardDaysByWeek(array $rows): array
{
    $byWeek = [];
    foreach ($rows as $date => $row) {
        $week = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
        $byWeek[$week] ??= 0;
        $byWeek[$week] += $row['prescribed_hard_minutes'] > 0 && $row['session_type'] !== SessionType::Race ? 1 : 0;
    }

    return $byWeek;
}

/**
 * The weeks whose plan holds a Tempo or Interval with work, from the same inputs with no trial at all.
 *
 * @return list<string>
 */
function weeksWithQualitySlot(PlanInputs $inputs): array
{
    $plain = trialPlan(new PlanInputs(...[...get_object_vars($inputs), 'timeTrialAimSec' => null]));

    return array_values(array_unique(array_map(
        static fn (string $date): string => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
        array_keys(array_filter($plain, static fn (array $row): bool => in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true) && $row['prescribed_hard_minutes'] > 0)),
    )));
}

it('schedules a race season\'s trials counted back from race week, never in its last three weeks', function (): void {
    $rows = trialPlan(trialPlanInputs(10_000.0));

    expect(trialWeeksOf($rows))->toBe(['2026-10-05', '2026-11-09'])
        ->and(array_filter(array_keys(trialRows($rows)), static fn (string $date): bool => $date >= '2026-11-23'))->toBe([]);
});

it('keeps a trial moved off a scheduled deload week in that week\'s cycle once the deload is past', function (): void {
    $inputs = trialPlanInputs(10_000.0, today: '2026-08-31', trials: [['date' => '2026-08-20', 'retry' => false, 'skipped' => true]]);

    expect(TimeTrialSchedule::dueWeeks($inputs))->toContain('2026-08-24')
        ->and(trialPlan(trialPlanInputs(10_000.0, today: '2026-08-17'))['2026-08-24']['phase'])->toBe(PlanPhase::Deload)
        ->and(trialWeeksOf(trialPlan(trialPlanInputs(10_000.0, today: '2026-08-17'))))->toContain('2026-08-17')
        ->and(in_array('2026-08-31', weeksWithQualitySlot($inputs), true))->toBeTrue()
        ->and(trialWeeksOf(trialPlan($inputs)))->not->toContain('2026-08-31');
});

it('runs a 10K trial for a half marathon goal and a 5K one for a 10K goal', function (float $raceDistanceM, int $trialM): void {
    $trials = trialRows(trialPlan(trialPlanInputs($raceDistanceM, aimSec: $trialM === 5_000 ? 1_400 : 2_950)));

    expect($trials)->not->toBeEmpty()
        ->and(array_values(array_unique(array_map(static fn (array $row): int => $row['prescription_race_context']['distance_m'], $trials))))->toBe([$trialM]);
})->with([
    '10K goal' => [10_000.0, 5_000],
    'half goal' => [21_097.5, 10_000],
    'marathon goal' => [42_195.0, 10_000],
]);

it('schedules a season with no race from its third week, then every six', function (): void {
    $rows = trialPlan(trialPlanInputs(null));

    expect(trialWeeksOf($rows))->toBe(['2026-10-19', '2026-11-30'])
        ->and(array_column(trialRows($rows), 'prescription_race_context'))->each->toMatchArray(['distance_m' => 5_000, 'aim_time_sec' => 1_400, 'retry' => 0]);
});

it('puts no trial anywhere without a supported time to aim around', function (): void {
    expect(trialRows(trialPlan(trialPlanInputs(10_000.0, aimSec: null))))->toBe([]);
});

it('moves a trial due in a deload week to the next week with a quality slot', function (): void {
    $inputs = trialPlanInputs(null, today: '2026-10-19', deload: true);
    $next = array_find(weeksWithQualitySlot($inputs), static fn (string $week): bool => $week > '2026-10-19');

    expect(trialWeeksOf(trialPlan($inputs))[0])->toBe($next)
        ->and(array_column(trialRows(trialPlan($inputs)), 'prescription_race_context')[0]['retry'])->toBe(0);
});

it('replaces the week\'s first quality session, so a trial week never gains a hard day', function (?float $raceDistanceM): void {
    $withTrials = trialPlan(trialPlanInputs($raceDistanceM));
    $without = trialPlan(trialPlanInputs($raceDistanceM, aimSec: null));

    foreach (trialRows($withTrials) as $date => $row) {
        $week = Carbon::parse($date)->startOfWeek(Carbon::MONDAY);
        $firstQuality = array_find(
            array_keys($without),
            static fn (string $day): bool => $day >= $week->toDateString() && in_array($without[$day]['session_type'], [SessionType::Tempo, SessionType::Interval], true) && $without[$day]['prescribed_hard_minutes'] > 0,
        );
        expect($date)->toBe($firstQuality)
            ->and($row['session_type'])->toBe($without[$date]['session_type']);
    }

    expect(hardDaysByWeek($withTrials))->toBe(hardDaysByWeek($without));
})->with([
    'race season' => [10_000.0],
    'half marathon season' => [21_097.5],
    'no race' => [null],
]);

it('takes a goal-pace week\'s goal-pace session, leaving that week with no goal-pace work', function (): void {
    $rows = trialPlan(trialPlanInputs(10_000.0, band: RaceAmbitionState::OnTrack));
    $goalPaceWeeks = array_values(array_unique(array_map(
        static fn (string $date): string => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
        array_keys(array_filter($rows, static fn (array $row): bool => GoalPaceWork::isGoalPace($row['prescription_race_context']))),
    )));

    expect(GoalPaceWork::inWindow(Carbon::parse('2026-11-09'), Carbon::parse(TRIAL_RACE_DAY), 10_000.0))->toBeTrue()
        ->and(trialWeeksOf($rows))->toContain('2026-11-09')
        ->and($goalPaceWeeks)->not->toContain('2026-11-09')
        ->and($goalPaceWeeks)->toContain('2026-11-23');
});

it('offers a skipped trial once more in the following week\'s first quality slot', function (): void {
    $inputs = trialPlanInputs(null, today: '2026-11-09', trials: [['date' => '2026-11-05', 'retry' => false, 'skipped' => true]]);
    $nextWeekSlot = in_array('2026-11-09', weeksWithQualitySlot($inputs), true);
    $retries = array_filter(trialRows(trialPlan($inputs)), static fn (array $row): bool => $row['prescription_race_context']['retry'] === 1);

    expect($nextWeekSlot)->toBeTrue()
        ->and(array_map(static fn (string $date): string => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(), array_keys($retries)))
        ->toBe(['2026-11-09']);
});

it('offers no retry for a trial that was run, a skipped retry, or a following deload week', function (string $today, array $trials): void {
    $rows = trialPlan(trialPlanInputs(null, today: $today, trials: $trials));

    expect(array_filter(trialRows($rows), static fn (array $row, string $date): bool => $date < '2026-11-30', ARRAY_FILTER_USE_BOTH))->toBe([]);
})->with([
    'run' => ['2026-11-09', [['date' => '2026-11-05', 'retry' => false, 'skipped' => false]]],
    'retry skipped' => ['2026-11-16', [['date' => '2026-11-05', 'retry' => false, 'skipped' => true], ['date' => '2026-11-12', 'retry' => true, 'skipped' => true]]],
    'deload the week after' => ['2026-10-26', [['date' => '2026-10-22', 'retry' => false, 'skipped' => true]]],
]);

it('writes the trial day as the trial distance alone, at the aim', function (): void {
    $date = array_key_first(trialRows(trialPlan(trialPlanInputs(null))));
    $row = trialPlan(trialPlanInputs(null))[$date];
    $segments = SegmentGenerator::forPrescription(
        $row['session_type'],
        $row['phase'],
        TimeTrial::dayKm($row['session_type'], $row['prescription_race_context']) ?? 0.0,
        TRIAL_PACES,
        new IntensityPrescription($row['prescribed_hard_minutes'], $row['prescribed_pace_band'], $row['prescribed_pace_sec_per_km'], null, $row['prescription_race_context']),
    );

    expect($row['prescribed_pace_band'])->toBe(PaceBand::Interval)
        ->and($row['prescribed_pace_sec_per_km'])->toBe(280)
        ->and($row['fall_off_tilt'])->toBeNull()
        ->and(array_map(static fn ($segment): string => $segment->key->value, $segments))->toBe([SegmentKey::Main->value])
        ->and($segments[0]->km)->toBe(5.0)
        ->and($segments[0]->paceSecPerKm)->toBe(280);
});
