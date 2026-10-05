<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceAmbitionState;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\IntensityPrescription;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanInputs;
use App\Services\Run\Plan\SegmentGenerator;
use Illuminate\Support\Carbon;

const GOAL_PACE_RACE_DAY = '2026-12-19';

const GOAL_PACE_10K = 10_000.0;

const GOAL_PACE_HALF = 21_097.5;

const GOAL_PACE_MARATHON = 42_195.0;

/** Goal paces of 4:36, 5:00, 4:59 and 5:10 per km, against a supported marathon pace of 5:20. */
const GOAL_PACE_TIMES = [5_000 => 1380, 10_000 => 3000, 21_097 => 6300, 42_195 => 13_080];

/**
 * @param  array<string, array{verdict: IntentVerdict, hard_minutes: int}>  $recent
 */
function goalPaceInputs(
    float $distanceM,
    ?RaceAmbitionState $band,
    string $today = '2026-10-05',
    int $sessions = 4,
    array $recent = [],
    float $longRunKm = 18.0,
): PlanInputs {
    $raceDate = Carbon::parse(GOAL_PACE_RACE_DAY);
    $goalTimeSec = GOAL_PACE_TIMES[(int) $distanceM];

    return new PlanInputs(
        userId: 1,
        today: Carbon::parse($today),
        seasonStart: $raceDate->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($distanceM === GOAL_PACE_HALF ? 12 : 19),
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
        paces: ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270],
        longRunBaselineKm: $longRunKm,
        longRunCapKm: 32.0,
        recentPrescriptions: $recent,
        raceAmbitionState: $band,
    );
}

/** @return array<string, array<string, mixed>> */
function goalPacePlan(PlanInputs $inputs): array
{
    return app(Periodizer::class)->rowsFor($inputs);
}

/**
 * @param  array<string, array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function goalPaceSessions(array $rows): array
{
    return array_filter($rows, static fn (array $row): bool => $row['session_type'] !== SessionType::Long
        && $row['prescribed_hard_minutes'] > 0
        && isset($row['prescription_race_context']['band']));
}

/**
 * @param  array<string, array<string, mixed>>  $rows
 * @return list<string>
 */
function goalPaceWeeks(array $rows): array
{
    return array_values(array_unique(array_map(
        static fn (string $date): string => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
        array_keys(goalPaceSessions($rows)),
    )));
}

/**
 * @param  array<string, array<string, mixed>>  $rows
 * @return list<string>
 */
function hardDates(array $rows): array
{
    return array_keys(array_filter($rows, static fn (array $row): bool => $row['prescribed_hard_minutes'] > 0));
}

/** @return array{verdict: IntentVerdict, hard_minutes: int} */
function goalPaceHit(int $minutes): array
{
    return ['verdict' => IntentVerdict::Hit, 'hard_minutes' => $minutes];
}

it('opens the 10K window six weeks out, race week included', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26'));

    expect(goalPaceWeeks($rows))->toBe(['2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07'])
        ->and($rows['2026-11-05']['session_type'])->toBe(SessionType::Interval)
        ->and($rows['2026-11-05']['prescription_race_context'])->toBeNull()
        ->and($rows['2026-11-12']['prescribed_pace_sec_per_km'])->toBe(300);
});

it('opens the half window eight weeks out and keeps the deload week inside it easy', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, RaceAmbitionState::OnTrack));
    $deloadWeek = array_filter($rows, static fn (array $row, string $date): bool => $date >= '2026-11-02' && $date <= '2026-11-08', ARRAY_FILTER_USE_BOTH);

    expect($rows['2026-10-22']['phase'])->toBe(PlanPhase::Build)
        ->and($rows['2026-10-22']['prescription_race_context'])->toBeNull()
        ->and($rows['2026-10-22']['prescribed_pace_sec_per_km'])->toBe(295)
        ->and(goalPaceWeeks($rows))->toBe(['2026-10-26', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07'])
        ->and(array_values(array_unique(array_map(static fn (array $row): string => $row['phase']->value, $deloadWeek))))->toBe([PlanPhase::Deload->value])
        ->and(hardDates($deloadWeek))->toBe([]);
});

it('doses the half by band and phase, taper included', function (RaceAmbitionState $band, array $minutes): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, $band, recent: ['goal_pace' => goalPaceHit(40)]));

    expect([$rows['2026-11-12']['prescribed_hard_minutes'], $rows['2026-11-19']['prescribed_hard_minutes'], $rows['2026-12-10']['prescribed_hard_minutes']])
        ->toBe($minutes)
        ->and($rows['2026-12-10']['phase'])->toBe(PlanPhase::Taper)
        ->and($rows['2026-12-10']['prescribed_pace_sec_per_km'])->toBe(299);
})->with([
    'on track' => [RaceAmbitionState::OnTrack, [30, 40, 20]],
    'ambitious' => [RaceAmbitionState::Ambitious, [15, 20, 10]],
]);

it('doses 5K and 10K goal-pace reps by band', function (float $distanceM, RaceAmbitionState $band, int $peakMinutes): void {
    $peak = goalPacePlan(goalPaceInputs($distanceM, $band, '2026-10-26', recent: ['goal_pace' => goalPaceHit(24)]))['2026-11-19'];

    expect($peak['prescribed_hard_minutes'])->toBe($peakMinutes)
        ->and($peak['prescription_race_context']['band'])->toBe($band->value);
})->with([
    '5K on track' => [5_000.0, RaceAmbitionState::OnTrack, 20],
    '5K ambitious' => [5_000.0, RaceAmbitionState::Ambitious, 8],
    '10K on track' => [GOAL_PACE_10K, RaceAmbitionState::OnTrack, 24],
    '10K ambitious' => [GOAL_PACE_10K, RaceAmbitionState::Ambitious, 12],
]);

it('gives unsupported, low-evidence and unknown 10K and half goals no goal-pace work', function (float $distanceM, RaceAmbitionState $band): void {
    $rows = goalPacePlan(goalPaceInputs($distanceM, $band));

    expect(goalPaceSessions($rows))->toBe([])
        ->and($rows)->toBe(goalPacePlan(goalPaceInputs($distanceM, null)));
})->with([GOAL_PACE_10K, GOAL_PACE_HALF])->with([RaceAmbitionState::Unsupported, RaceAmbitionState::LowEvidence, RaceAmbitionState::Unknown]);

it('keeps exactly today\'s marathon plan for unsupported, low-evidence and unknown goals', function (RaceAmbitionState $band): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, $band));

    expect($rows)->toBe(goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, null)))
        ->and($rows['2026-11-05']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-11-08']['prescribed_pace_sec_per_km'])->toBe(320);
})->with([RaceAmbitionState::Unsupported, RaceAmbitionState::LowEvidence, RaceAmbitionState::Unknown]);

it('runs an on-track marathon tempo and race long at goal pace inside the window only', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::OnTrack));

    expect($rows['2026-10-15']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-10-18']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-10-29']['prescribed_pace_sec_per_km'])->toBe(310)
        ->and($rows['2026-10-29']['prescription_race_context']['band'])->toBe('on_track')
        ->and($rows['2026-11-01']['session_type'])->toBe(SessionType::Long)
        ->and($rows['2026-11-01']['prescribed_pace_band'])->toBe(PaceBand::Marathon)
        ->and($rows['2026-11-01']['prescribed_pace_sec_per_km'])->toBe(310);
});

it('gives an ambitious marathon goal pace only through a halved tempo, keeping the race long at supported pace', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::Ambitious, recent: ['race_tempo' => goalPaceHit(40)]));

    expect($rows['2026-10-29']['prescribed_pace_sec_per_km'])->toBe(310)
        ->and($rows['2026-10-29']['prescribed_hard_minutes'])->toBe(12)
        ->and($rows['2026-11-05']['prescribed_hard_minutes'])->toBe(17)
        ->and($rows['2026-11-01']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-11-01']['prescription_race_context'])->not->toHaveKey('band');
});

it('replaces the interval of a two-quality 10K week and never adds a hard day', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', sessions: 5));
    $week = array_filter($rows, static fn (array $row, string $date): bool => $date >= '2026-11-16' && $date <= '2026-11-22', ARRAY_FILTER_USE_BOTH);

    expect(array_keys(goalPaceSessions($week)))->toBe(['2026-11-19'])
        ->and($rows['2026-11-19']['session_type'])->toBe(SessionType::Interval)
        ->and($rows['2026-11-17']['session_type'])->toBe(SessionType::Tempo)
        ->and($rows['2026-11-17']['prescribed_pace_sec_per_km'])->toBe(295)
        ->and(hardDates($rows))->toBe(hardDates(goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::Unsupported, '2026-10-26', sessions: 5))));
});

it('turns a 10K week\'s only tempo into goal-pace reps when it has no interval', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', recent: ['goal_pace' => goalPaceHit(16)]));
    $tempo = $rows['2026-11-19'];
    $prescription = new IntensityPrescription($tempo['prescribed_hard_minutes'], $tempo['prescribed_pace_band'], $tempo['prescribed_pace_sec_per_km'], null, $tempo['prescription_race_context']);
    $segments = SegmentGenerator::forPrescription($tempo['session_type'], $tempo['phase'], 11.0, ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270], $prescription);

    expect($tempo['session_type'])->toBe(SessionType::Tempo)
        ->and($tempo['prescribed_hard_minutes'])->toBe(20)
        ->and(array_values(array_filter($segments, static fn ($segment): bool => $segment->key === SegmentKey::Interval)))->toHaveCount(5)
        ->and(hardDates($rows))->toBe(hardDates(goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::LowEvidence, '2026-10-26'))));
});

it('keeps goal-pace progression apart from interval history', function (): void {
    $fromIntervals = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', recent: ['interval' => goalPaceHit(16)]));
    $fromGoalPace = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', recent: ['goal_pace' => goalPaceHit(16)]));

    expect($fromIntervals['2026-11-19']['prescribed_hard_minutes'])->toBe(8)
        ->and($fromIntervals['2026-11-05']['prescribed_hard_minutes'])->toBe(12)
        ->and($fromGoalPace['2026-11-19']['prescribed_hard_minutes'])->toBe(20)
        ->and($fromGoalPace['2026-11-05']['prescribed_hard_minutes'])->toBe(6);
});

it('trims goal-pace reps to the distance a short day holds instead of dropping them', function (): void {
    $peak = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', sessions: 5, recent: ['goal_pace' => goalPaceHit(24)], longRunKm: 10.0))['2026-11-19'];

    expect($peak['session_type'])->toBe(SessionType::Interval)
        ->and($peak['prescribed_hard_minutes'])->toBeGreaterThan(0)->toBeLessThan(24)
        ->and($peak['prescribed_hard_minutes'] % 4)->toBe(0)
        ->and($peak['prescription_reason'])->toBe('bounded by the distance this day holds');
});
