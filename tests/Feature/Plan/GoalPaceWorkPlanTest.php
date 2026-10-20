<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\FallOffTilt;
use App\Enums\IntentVerdict;
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
use App\Services\Run\Plan\SessionSegment;
use Illuminate\Support\Carbon;

const GOAL_PACE_RACE_DAY = '2026-12-19';

const GOAL_PACE_10K = 10_000.0;

const GOAL_PACE_HALF = 21_097.5;

const GOAL_PACE_MARATHON = 42_195.0;

/** Goal paces of 4:36, 5:00, 4:59 and 5:10 per km, against a supported marathon pace of 5:20; an unsupported goal's supported times. */
const GOAL_PACE_TIMES = [5_000 => 1380, 10_000 => 3000, 21_097 => 6300, 42_195 => 13_080];

/** An ambitious goal's supported time, as a multiple of its goal time. */
const GOAL_PACE_AMBITIOUS_SUPPORTED = 1.05;

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
    ?float $projectedSec = null,
    ?int $lastBuildQualityMinutes = null,
    ?int $lastPeakGoalPaceMinutes = null,
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
        projectedRaceSeconds: $projectedSec ?? (float) $goalTimeSec,
        raceGoalTimeSec: $goalTimeSec,
        paces: ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270],
        longRunBaselineKm: $longRunKm,
        longRunCapKm: 32.0,
        recentPrescriptions: $recent,
        raceAmbitionState: $band,
        raceSteppingStoneTimeSec: match ($band) {
            RaceAmbitionState::Unsupported => RaceAmbitionAssessor::steppingStoneTimeSec($goalTimeSec),
            RaceAmbitionState::Ambitious => RaceAmbitionAssessor::steppingStoneTimeSec((int) round($goalTimeSec * GOAL_PACE_AMBITIOUS_SUPPORTED)),
            default => null,
        },
        lastBuildQualityHardMinutes: $lastBuildQualityMinutes,
        lastPeakGoalPaceHardMinutes: $lastPeakGoalPaceMinutes,
    );
}

/** @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}> */
function goalPacePlan(PlanInputs $inputs): array
{
    return app(Periodizer::class)->rowsFor($inputs);
}

/**
 * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>  $rows
 * @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>
 */
function goalPaceSessions(array $rows): array
{
    return array_filter($rows, static fn (array $row): bool => $row['session_type'] !== SessionType::Long
        && $row['prescribed_hard_minutes'] > 0
        && isset($row['prescription_race_context']['band']));
}

/**
 * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>  $rows
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
 * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>  $rows
 * @return list<string>
 */
function hardDates(array $rows): array
{
    return array_keys(array_filter($rows, static fn (array $row): bool => $row['prescribed_hard_minutes'] > 0
        && ! FastFinishLongRun::isFastFinish($row['prescription_race_context'])));
}

/** @return array{verdict: IntentVerdict, hard_minutes: int} */
function goalPaceHit(int $minutes): array
{
    return ['verdict' => IntentVerdict::Hit, 'hard_minutes' => $minutes];
}

/**
 * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>  $rows
 * @return array<string, int>
 */
function qualityMinutesInPhase(array $rows, PlanPhase $phase): array
{
    return array_map(
        static fn (array $row): int => $row['prescribed_hard_minutes'],
        array_filter($rows, static fn (array $row): bool => $row['phase'] === $phase
            && in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true)
            && $row['prescribed_hard_minutes'] > 0),
    );
}

it('opens the 10K window six weeks out, race week included', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26'));

    expect(goalPaceWeeks($rows))->toBe(['2026-11-09', '2026-11-23', '2026-11-30', '2026-12-07'])
        ->and($rows['2026-11-05']['session_type'])->toBe(SessionType::Interval)
        ->and($rows['2026-11-05']['prescription_race_context'])->toBeNull()
        ->and($rows['2026-11-12']['prescribed_pace_sec_per_km'])->toBe(300);
});

it('opens the half window eight weeks out and keeps the deload week inside it easy', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, RaceAmbitionState::OnTrack));
    $deloadWeek = array_filter($rows, static fn (array $row, string $date): bool => $date >= '2026-11-09' && $date <= '2026-11-15', ARRAY_FILTER_USE_BOTH);

    expect($rows['2026-10-22']['phase'])->toBe(PlanPhase::Build)
        ->and($rows['2026-10-22']['prescription_race_context'])->toBeNull()
        ->and($rows['2026-10-22']['prescribed_pace_sec_per_km'])->toBe(295)
        ->and(goalPaceWeeks($rows))->toBe(['2026-10-26', '2026-11-02', '2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07'])
        ->and(array_values(array_unique(array_map(static fn (array $row): string => $row['phase']->value, $deloadWeek))))->toBe([PlanPhase::Deload->value])
        ->and(hardDates($deloadWeek))->toBe([]);
});

it('doses the half by band and phase, taper included', function (RaceAmbitionState $band, array $minutes, int $taperPace): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, $band, recent: ['goal_pace' => goalPaceHit(40)]));

    expect([$rows['2026-11-05']['prescribed_hard_minutes'], $rows['2026-11-19']['prescribed_hard_minutes'], $rows['2026-12-10']['prescribed_hard_minutes']])
        ->toBe($minutes)
        ->and($rows['2026-12-10']['phase'])->toBe(PlanPhase::Taper)
        ->and($rows['2026-12-10']['prescribed_pace_sec_per_km'])->toBe($taperPace);
})->with([
    'on track' => [RaceAmbitionState::OnTrack, [30, 40, 20], 299],
    'ambitious' => [RaceAmbitionState::Ambitious, [30, 40, 20], 304],
]);

it('doses 5K and 10K goal-pace reps by band', function (float $distanceM, RaceAmbitionState $band, int $peakMinutes): void {
    $peak = goalPacePlan(goalPaceInputs($distanceM, $band, '2026-10-26', recent: ['goal_pace' => goalPaceHit(24)]))['2026-11-26'];

    expect($peak['prescribed_hard_minutes'])->toBe($peakMinutes)
        ->and(data_get($peak['prescription_race_context'], 'band'))->toBe($band->value);
})->with([
    '5K on track' => [5_000.0, RaceAmbitionState::OnTrack, 20],
    '5K ambitious' => [5_000.0, RaceAmbitionState::Ambitious, 20],
    '10K on track' => [GOAL_PACE_10K, RaceAmbitionState::OnTrack, 24],
    '10K ambitious' => [GOAL_PACE_10K, RaceAmbitionState::Ambitious, 24],
]);

it('gives low-evidence and unknown 10K and half goals no goal-pace work', function (float $distanceM, RaceAmbitionState $band): void {
    $rows = goalPacePlan(goalPaceInputs($distanceM, $band));

    expect(goalPaceSessions($rows))->toBe([])
        ->and($rows)->toBe(goalPacePlan(goalPaceInputs($distanceM, null)));
})->with([GOAL_PACE_10K, GOAL_PACE_HALF])->with([RaceAmbitionState::LowEvidence, RaceAmbitionState::Unknown]);

it('keeps exactly today\'s marathon plan for low-evidence and unknown goals', function (RaceAmbitionState $band): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, $band));

    expect($rows)->toBe(goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, null)))
        ->and($rows['2026-11-05']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-11-08']['prescribed_pace_sec_per_km'])->toBe(320);
})->with([RaceAmbitionState::LowEvidence, RaceAmbitionState::Unknown]);

it('runs unsupported 5K and 10K goal-pace reps at the stepping-stone pace with the full on-track dose', function (float $distanceM, int $pace): void {
    $recent = ['goal_pace' => goalPaceHit(24)];
    $rows = goalPacePlan(goalPaceInputs($distanceM, RaceAmbitionState::Unsupported, '2026-10-26', recent: $recent));
    $onTrack = goalPacePlan(goalPaceInputs($distanceM, RaceAmbitionState::OnTrack, '2026-10-26', recent: $recent));

    expect(goalPaceWeeks($rows))->toBe(goalPaceWeeks($onTrack))
        ->and($rows['2026-11-26']['prescribed_hard_minutes'])->toBe($onTrack['2026-11-26']['prescribed_hard_minutes'])
        ->and($rows['2026-11-26']['prescribed_pace_sec_per_km'])->toBe($pace)
        ->and(data_get($rows['2026-11-26']['prescription_race_context'], 'band'))->toBe('unsupported')
        ->and(hardDates($rows))->toBe(hardDates(goalPacePlan(goalPaceInputs($distanceM, RaceAmbitionState::LowEvidence, '2026-10-26'))));
})->with([
    '5K' => [5_000.0, 268],
    '10K' => [GOAL_PACE_10K, 291],
]);

it('runs an unsupported half goal-pace block at the stepping-stone pace with the full on-track dose, taper included', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, RaceAmbitionState::Unsupported, recent: ['goal_pace' => goalPaceHit(40)]));

    expect([$rows['2026-11-05']['prescribed_hard_minutes'], $rows['2026-11-19']['prescribed_hard_minutes'], $rows['2026-12-10']['prescribed_hard_minutes']])
        ->toBe([30, 40, 20])
        ->and($rows['2026-11-19']['prescribed_pace_sec_per_km'])->toBe(290)
        ->and($rows['2026-12-10']['prescribed_pace_sec_per_km'])->toBe(290)
        ->and($rows['2026-10-22']['prescription_race_context'])->toBeNull()
        ->and($rows['2026-10-22']['prescribed_pace_sec_per_km'])->toBe(295);
});

it('learns unsupported goal-pace progression from goal-pace history, not intervals', function (): void {
    $fromIntervals = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::Unsupported, '2026-10-26', recent: ['interval' => goalPaceHit(16)]));
    $fromGoalPace = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::Unsupported, '2026-10-26', recent: ['goal_pace' => goalPaceHit(16)]));

    expect($fromIntervals['2026-11-26']['prescribed_hard_minutes'])->toBe(12)
        ->and($fromIntervals['2026-11-26']['prescription_reason'])->toBe('carried over from the last build quality session')
        ->and($fromGoalPace['2026-11-26']['prescribed_hard_minutes'])->toBe(20);
});

it('runs an unsupported marathon tempo and race long at the stepping-stone pace with on-track minutes inside the window only', function (): void {
    $recent = ['race_tempo' => goalPaceHit(40), 'race_long' => goalPaceHit(40)];
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::Unsupported, recent: $recent));
    $onTrack = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::OnTrack, recent: $recent));

    expect($rows['2026-10-15']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-10-18']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-10-29']['prescribed_pace_sec_per_km'])->toBe(301)
        ->and($rows['2026-10-29']['prescribed_hard_minutes'])->toBe($onTrack['2026-10-29']['prescribed_hard_minutes'])
        ->and(data_get($rows['2026-10-29']['prescription_race_context'], 'band'))->toBe('unsupported')
        ->and($rows['2026-11-01']['session_type'])->toBe(SessionType::Long)
        ->and($rows['2026-11-01']['prescribed_pace_band'])->toBe(PaceBand::Marathon)
        ->and($rows['2026-11-01']['prescribed_pace_sec_per_km'])->toBe(301)
        ->and($rows['2026-11-01']['prescribed_hard_minutes'])->toBe($onTrack['2026-11-01']['prescribed_hard_minutes'])
        ->and(hardDates($rows))->toBe(hardDates($onTrack));
});

it('runs an on-track marathon tempo and race long at goal pace inside the window only', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::OnTrack));

    expect($rows['2026-10-15']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-10-18']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-10-29']['prescribed_pace_sec_per_km'])->toBe(310)
        ->and(data_get($rows['2026-10-29']['prescription_race_context'], 'band'))->toBe('on_track')
        ->and($rows['2026-11-01']['session_type'])->toBe(SessionType::Long)
        ->and($rows['2026-11-01']['prescribed_pace_band'])->toBe(PaceBand::Marathon)
        ->and($rows['2026-11-01']['prescribed_pace_sec_per_km'])->toBe(310);
});

it('gives an ambitious marathon the full race tempo at the stepping-stone pace with a goal-pace final unit, keeping the race long at supported pace', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::Ambitious, recent: ['race_tempo' => goalPaceHit(40)]));
    $onTrack = goalPacePlan(goalPaceInputs(GOAL_PACE_MARATHON, RaceAmbitionState::OnTrack, recent: ['race_tempo' => goalPaceHit(40)]));

    expect($rows['2026-10-29']['prescribed_pace_sec_per_km'])->toBe(316)
        ->and(data_get($rows['2026-10-29']['prescription_race_context'], 'final_pace_sec_per_km'))->toBe(310)
        ->and($rows['2026-10-29']['prescribed_hard_minutes'])->toBe($onTrack['2026-10-29']['prescribed_hard_minutes'])
        ->and($rows['2026-11-05']['prescribed_hard_minutes'])->toBe($onTrack['2026-11-05']['prescribed_hard_minutes'])
        ->and($rows['2026-11-01']['prescribed_pace_sec_per_km'])->toBe(320)
        ->and($rows['2026-11-01']['prescription_race_context'])->not->toHaveKey('band');
});

it('replaces the interval of a two-quality 10K week and never adds a hard day', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', sessions: 5));
    $week = array_filter($rows, static fn (array $row, string $date): bool => $date >= '2026-11-23' && $date <= '2026-11-29', ARRAY_FILTER_USE_BOTH);

    expect(array_keys(goalPaceSessions($week)))->toBe(['2026-11-26'])
        ->and($rows['2026-11-26']['session_type'])->toBe(SessionType::Interval)
        ->and($rows['2026-11-24']['session_type'])->toBe(SessionType::Tempo)
        ->and($rows['2026-11-24']['prescribed_pace_sec_per_km'])->toBe(295)
        ->and(hardDates($rows))->toBe(hardDates(goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::LowEvidence, '2026-10-26', sessions: 5))));
});

it('turns a 10K week\'s only tempo into goal-pace reps when it has no interval', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', recent: ['goal_pace' => goalPaceHit(16)]));
    $tempo = $rows['2026-11-26'];
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

    expect($fromIntervals['2026-11-26']['prescribed_hard_minutes'])->toBe(12)
        ->and($fromIntervals['2026-11-05']['prescribed_hard_minutes'])->toBe(12)
        ->and($fromGoalPace['2026-11-26']['prescribed_hard_minutes'])->toBe(20)
        ->and($fromGoalPace['2026-11-05']['prescribed_hard_minutes'])->toBe(6);
});

it('trims goal-pace reps to the distance a short day holds instead of dropping them', function (): void {
    $peak = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-10-26', sessions: 5, recent: ['goal_pace' => goalPaceHit(24)], longRunKm: 10.0))['2026-11-26'];

    expect($peak['session_type'])->toBe(SessionType::Interval)
        ->and($peak['prescribed_hard_minutes'])->toBeGreaterThan(0)->toBeLessThan(24)
        ->and($peak['prescribed_hard_minutes'] % 4)->toBe(0)
        ->and($peak['prescription_reason'])->toBe('bounded by the distance this day holds');
});

it('carries the last build quality minutes into peak goal-pace work instead of the cold start', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, projectedSec: 4200.0));
    $build = qualityMinutesInPhase($rows, PlanPhase::Build);
    $peak = qualityMinutesInPhase($rows, PlanPhase::Peak);

    expect(array_last($build))->toBe(20)
        ->and(array_values($peak))->toBe([20, 20, 20])
        ->and(array_keys(goalPaceSessions(array_intersect_key($rows, $peak))))->toBe(array_keys($peak))
        ->and($rows[nonNull(array_key_first($peak))]['prescription_reason'])->toBe('carried over from the last build quality session');
});

it('caps the carried-over minutes at the peak goal-pace target', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, recent: ['tempo' => goalPaceHit(30)], projectedSec: 4200.0));
    $build = qualityMinutesInPhase($rows, PlanPhase::Build);

    expect(array_last($build))->toBe(30)
        ->and(array_values(qualityMinutesInPhase($rows, PlanPhase::Peak)))->toBe([24, 24, 24]);
});

it('carries the persisted last build quality minutes once build is behind the athlete', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-11-23', lastBuildQualityMinutes: 16));

    expect(array_values(qualityMinutesInPhase($rows, PlanPhase::Peak)))->toBe([16, 16]);
});

it('keeps later peak progression in the goal-pace family', function (IntentVerdict $verdict, int $peakMinutes): void {
    $recent = ['goal_pace' => ['verdict' => $verdict, 'hard_minutes' => 20]];
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-11-23', recent: $recent, lastBuildQualityMinutes: 20));

    expect(array_values(qualityMinutesInPhase($rows, PlanPhase::Peak)))->toBe([$peakMinutes, $peakMinutes]);
})->with([
    'hit' => [IntentVerdict::Hit, 24],
    'too hard' => [IntentVerdict::TooHard, 16],
]);

it('keeps peak quality level with build in a two-quality week', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, sessions: 5, recent: ['interval' => goalPaceHit(12)]));
    $weekly = static function (array $minutes): array {
        $byWeek = [];
        foreach ($minutes as $date => $hard) {
            $week = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $byWeek[$week] = ($byWeek[$week] ?? 0) + $hard;
        }

        return $byWeek;
    };
    $build = $weekly(qualityMinutesInPhase($rows, PlanPhase::Build));

    expect(nonNull(collect($weekly(qualityMinutesInPhase($rows, PlanPhase::Peak)))->min()))->toBeGreaterThanOrEqual(nonNull(array_last($build)));
});

it('gives ambitious and unsupported goals the same goal-pace dose', function (float $distanceM): void {
    $minutes = static fn (RaceAmbitionState $band): array => array_map(
        static fn (array $row): int => $row['prescribed_hard_minutes'],
        goalPaceSessions(goalPacePlan(goalPaceInputs($distanceM, $band, '2026-10-26', recent: ['goal_pace' => goalPaceHit(24)]))),
    );

    expect($minutes(RaceAmbitionState::Ambitious))->toBe($minutes(RaceAmbitionState::Unsupported))
        ->and($minutes(RaceAmbitionState::Ambitious))->not->toBe([]);
})->with([5_000.0, GOAL_PACE_10K, GOAL_PACE_HALF]);

it('runs every ambitious work unit but the last at the stepping-stone pace and the last at the goal pace', function (): void {
    $peak = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::Ambitious, '2026-10-26', recent: ['goal_pace' => goalPaceHit(24)]))['2026-11-26'];
    $prescription = new IntensityPrescription($peak['prescribed_hard_minutes'], $peak['prescribed_pace_band'], $peak['prescribed_pace_sec_per_km'], null, $peak['prescription_race_context']);
    $reps = array_values(array_filter(
        SegmentGenerator::forPrescription($peak['session_type'], $peak['phase'], 16.0, ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270], $prescription),
        static fn ($segment): bool => $segment->key === SegmentKey::Interval,
    ));

    expect($peak['prescribed_pace_sec_per_km'])->toBe(306)
        ->and(array_map(static fn ($rep): ?int => $rep->paceSecPerKm, $reps))->toBe([306, 306, 306, 306, 306, 300]);
});

it('gives a 10K ambitious four-run plan at least the last build quality minutes in every peak week', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::Ambitious, projectedSec: 4200.0));
    $build = qualityMinutesInPhase($rows, PlanPhase::Build);

    expect(array_last($build))->toBe(20)
        ->and(array_values(qualityMinutesInPhase($rows, PlanPhase::Peak)))->toBe([20, 20, 20]);
});

it('closes an ambitious half peak block with its last quarter at the goal pace', function (): void {
    $peak = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, RaceAmbitionState::Ambitious, recent: ['goal_pace' => goalPaceHit(40)]))['2026-11-19'];
    $prescription = new IntensityPrescription($peak['prescribed_hard_minutes'], $peak['prescribed_pace_band'], $peak['prescribed_pace_sec_per_km'], null, $peak['prescription_race_context']);
    $blocks = array_values(array_filter(
        SegmentGenerator::forPrescription($peak['session_type'], $peak['phase'], 16.0, ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270], $prescription),
        static fn ($segment): bool => $segment->key === SegmentKey::Main,
    ));

    expect(array_map(static fn ($block): array => [$block->minutes, $block->paceSecPerKm], $blocks))->toBe([[30.0, 304], [10.0, 299]]);
});

/**
 * @param  array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, fall_off_tilt: FallOffTilt|null, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}  $row
 * @return list<SessionSegment>
 */
function goalPaceWorkUnits(array $row): array
{
    $prescription = new IntensityPrescription($row['prescribed_hard_minutes'], $row['prescribed_pace_band'], $row['prescribed_pace_sec_per_km'], null, $row['prescription_race_context']);

    return array_values(array_filter(
        SegmentGenerator::forPrescription($row['session_type'], $row['phase'], 16.0, ['easy' => 380, 'marathon' => 320, 'threshold' => 295, 'interval' => 270], $prescription),
        static fn ($segment): bool => in_array($segment->key, [SegmentKey::Interval, SegmentKey::Main], true) && $segment->paceLabel !== PaceBand::Easy,
    ));
}

it('tapers a 10K ambitious four-run plan to about half the last peak session at its pace and rep length', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::Ambitious, projectedSec: 4200.0));
    $peak = qualityMinutesInPhase($rows, PlanPhase::Peak);
    $taper = qualityMinutesInPhase($rows, PlanPhase::Taper);
    $unitsOf = static fn (string $date): array => array_map(static fn ($unit): array => [$unit->minutes, $unit->paceSecPerKm], goalPaceWorkUnits($rows[$date]));

    expect(array_values($taper))->toBe([12])
        ->and(array_last($peak))->toBe(20)
        ->and($unitsOf(nonNull(array_key_last($peak))))->toBe([[4.0, 306], [4.0, 306], [4.0, 306], [4.0, 306], [4.0, 300]])
        ->and($unitsOf(nonNull(array_key_first($taper))))->toBe([[4.0, 306], [4.0, 306], [3.0, 306], [1.0, 300]])
        ->and($rows[nonNull(array_key_first($taper))]['prescription_reason'])->toBe('half the last peak session, at its pace and rep length');
});

it('tapers from the persisted last peak session once peak is behind the athlete', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-12-07', lastPeakGoalPaceMinutes: 24));

    expect(array_values(qualityMinutesInPhase($rows, PlanPhase::Taper)))->toBe([12])
        ->and(array_map(static fn ($unit): ?float => $unit->minutes, goalPaceWorkUnits($rows['2026-12-10'])))->toBe([4.0, 4.0, 4.0]);
});

it('keeps the minimum structure when half the peak session is less, and peak reps with no peak session behind it', function (?int $peakMinutes, int $taperMinutes): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_10K, RaceAmbitionState::OnTrack, '2026-12-07', lastPeakGoalPaceMinutes: $peakMinutes));

    expect($rows['2026-12-10']['prescribed_hard_minutes'])->toBe($taperMinutes)
        ->and(array_unique(array_map(static fn ($unit): ?float => $unit->minutes, goalPaceWorkUnits($rows['2026-12-10']))))->toBe([4.0]);
})->with([
    'half of 8' => [8, 8],
    'no peak session' => [null, 8],
]);

it('tapers a half block to about half the last peak block in whole taper blocks, at its pace', function (): void {
    $rows = goalPacePlan(goalPaceInputs(GOAL_PACE_HALF, RaceAmbitionState::OnTrack, recent: ['goal_pace' => goalPaceHit(24)]));
    $peak = qualityMinutesInPhase($rows, PlanPhase::Peak);

    expect(array_last($peak))->toBe(26)
        ->and($rows['2026-12-10']['prescribed_hard_minutes'])->toBe(10)
        ->and($rows['2026-12-10']['prescribed_pace_sec_per_km'])->toBe($rows[nonNull(array_key_last($peak))]['prescribed_pace_sec_per_km']);
});
