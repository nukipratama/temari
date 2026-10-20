<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use LogicException;
use App\Enums\FallOffTilt;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Metrics\HeartRateZones;

/**
 * Turns a day's `(session_type, phase)` into its full ordered list of {@see SessionSegment}s —
 * warmup, main effort, interval reps — combining the athlete's CURRENT long-run baseline,
 * phase-derived volume multiplier and VDOT-derived paces. Render-time only: nothing here is stored
 * on a row (see `docs/features/plan-periodizer.md`). `WeekPlanBuilder` only decides
 * `session_type`/`phase`.
 *
 * A day's distance is the WHOLE outing, warmup included: {@see self::kmFor()} carves the
 * fixed-duration warmup (and an Interval day's recovery jogs) out of that budget, so what the card
 * says and what {@see SessionMatcher} grades are the same run. A cooldown is never prescribed. See
 * `docs/decisions/a-session-is-the-whole-outing.md`.
 */
final class SegmentGenerator
{
    /** Medium/Short scale proportionally under the week's Long run — the same fractions the retired distance-band lookup used. */
    private const float MEDIUM_FRACTION_OF_LONG = 0.65;

    private const float SHORT_FRACTION_OF_LONG = 0.40;

    /** @var array<string, float> session_type value => warmup minutes */
    private const array WARMUP_MINUTES = [
        'tempo' => 10.0,
        'interval' => 12.0,
    ];

    /**
     * A warmup never eats more than half its own session: on a taper week at the long-run floor the
     * fixed-duration warmup would otherwise exceed the whole day.
     */
    private const float MAX_WARMUP_SHARE = 0.5;

    /**
     * Threshold blocks / recovery length by phase, minutes: several shorter blocks early, fewer and
     * longer as the athlete adapts, one continuous effort at Peak; Taper breaks it up again.
     *
     * @var array<string, array{0: int, 1: float}>
     */
    private const array TEMPO_BLOCK_TABLE = [
        'base' => [3, 2.0],
        'build' => [2, 2.0],
        'peak' => [1, 0.0],
        'taper' => [2, 2.0],
    ];

    /**
     * Rep length / recovery length by phase, minutes. Interval only occurs in Build and in
     * Peak/Taper for a non-marathon-distance race (see {@see WeekPlanBuilder::phaseQualitySlots()}).
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const array INTERVAL_REP_TABLE = [
        'build' => [3.0, 2.0],
        'peak' => [4.0, 2.0],
        'taper' => [2.0, 3.0],
    ];

    public static function workUnitMinutes(SessionType $sessionType, PlanPhase $phase, int $workBasisMinutes): int
    {
        if ($sessionType === SessionType::Long) {
            return max(1, $workBasisMinutes);
        }

        if ($sessionType === SessionType::Interval) {
            return (int) (self::INTERVAL_REP_TABLE[$phase->value][0] ?? 3.0);
        }

        [$blocks] = self::TEMPO_BLOCK_TABLE[$phase->value] ?? self::TEMPO_BLOCK_TABLE['build'];

        return max(1, (int) ceil($workBasisMinutes / $blocks));
    }

    /**
     * The WHOLE distance this session asks for, warmup included, before any redistribution scale.
     * Pace-independent, so {@see VolumeRedistributor} can sum a week without paces. A `Race` day asks
     * for the race distance, untouched by the volume multiplier. The ceilings bind here, on the
     * prescription every session type derives `$effectiveLong` from.
     *
     * @param  float  $longRunCapKm  {@see TrainingBaseline}'s `long_run_cap_km`
     * @param  ?float  $raceDistanceM  the active {@see \App\Models\RaceGoal}'s distance, required only on a `Race` day
     * @param  float  $longRunProgressionCapKm  {@see TrainingBaseline}'s `long_run_progression_cap_km`
     * @param  ?FallOffTilt  $fallOffTilt  the row's persisted tilt, which lengthens only the Long day itself
     * @param  array<string, int|float|string>|null  $raceContext  the row's persisted race context, which sizes a time trial day from the trial
     */
    public static function coreKmFor(
        SessionType $sessionType,
        bool $isPrimaryEasy,
        float $longRunBaselineKm,
        float $volumeMultiplier,
        float $longRunCapKm,
        ?float $raceDistanceM = null,
        float $longRunProgressionCapKm = INF,
        ?FallOffTilt $fallOffTilt = null,
        ?array $raceContext = null,
    ): float {
        if ($sessionType === SessionType::Rest) {
            return 0.0;
        }

        $trialKm = TimeTrial::dayKm($sessionType, $raceContext);
        if ($trialKm !== null) {
            return $trialKm;
        }

        if ($sessionType === SessionType::Race) {
            return round(($raceDistanceM ?? 0.0) / 1000.0, 1);
        }

        $effectiveLong = min($longRunBaselineKm * $volumeMultiplier, $longRunCapKm);

        return round(min($longRunProgressionCapKm, match ($sessionType) {
            SessionType::Long => min($longRunBaselineKm * $volumeMultiplier * ($fallOffTilt?->longRunFactor() ?? 1.0), $longRunCapKm),
            SessionType::Tempo => $effectiveLong * self::MEDIUM_FRACTION_OF_LONG,
            SessionType::Interval => $effectiveLong * self::SHORT_FRACTION_OF_LONG,
            SessionType::Easy => $effectiveLong * ($isPrimaryEasy ? self::MEDIUM_FRACTION_OF_LONG : self::SHORT_FRACTION_OF_LONG),
        }), 1);
    }

    /**
     * What a generated session actually asks the athlete to run, or null when no VDOT estimate lets
     * its fixed-duration parts become distance. Usually {@see self::coreKmFor()} again; an Interval
     * day's whole fixed-duration reps cannot hit an arbitrary budget, so this is what the day asks
     * for while the budget sizes the week.
     *
     * @param  list<SessionSegment>  $segments
     */
    public static function segmentSumKm(array $segments): ?float
    {
        if ($segments === []) {
            return null;
        }

        $km = 0.0;
        foreach ($segments as $segment) {
            if ($segment->km === null) {
                return null;
            }

            $km += $segment->km;
        }

        return round($km, 1);
    }

    /**
     * Carries the race DISTANCE rather than the marathon-or-not flag derived from it, since a `Race`
     * day needs the distance itself.
     *
     * @param  ?float  $raceDistanceM  the active {@see \App\Models\RaceGoal}'s distance, or null in self-scaled mode
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces  seconds per kilometre; null when the athlete has no VDOT estimate yet
     * @param  float  $longRunCapKm  {@see TrainingBaseline}'s `long_run_cap_km`
     * @param  float  $volumeScale  from {@see VolumeRedistributor} — 1.0 outside a redistributed week
     * @param  ?int  $raceGoalTimeSec  the active race's `goal_time_sec`, which is what a `Race` day is run at
     * @param  float  $longRunProgressionCapKm  {@see TrainingBaseline}'s `long_run_progression_cap_km`
     * @return list<SessionSegment>
     */
    public static function generate(
        SessionType $sessionType,
        PlanPhase $phase,
        ?float $raceDistanceM,
        bool $isPrimaryEasy,
        float $longRunBaselineKm,
        float $volumeMultiplier,
        float $longRunCapKm,
        ?array $paces,
        float $volumeScale = 1.0,
        ?int $raceGoalTimeSec = null,
        float $longRunProgressionCapKm = INF,
        ?FallOffTilt $fallOffTilt = null,
    ): array {
        $coreKm = self::coreKmFor($sessionType, $isPrimaryEasy, $longRunBaselineKm, $volumeMultiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm, $fallOffTilt);

        // The race is the distance it is: a redistributed week may scale the
        // training around it, never the event itself. A Long day remains under
        // both hard ceilings after redistribution rather than scaling through
        // them.
        $scaledCoreKm = $sessionType === SessionType::Race ? $coreKm : $coreKm * $volumeScale;
        if ($sessionType === SessionType::Long) {
            $scaledCoreKm = min($scaledCoreKm, $longRunCapKm, $longRunProgressionCapKm);
        }

        return self::forCoreKm(
            $sessionType,
            $phase,
            $raceDistanceM,
            $scaledCoreKm,
            $paces,
            $raceGoalTimeSec,
        );
    }

    /**
     * The segments of a session whose distance is already decided, such as the
     * core km a day was graded against.
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    public static function forCoreKm(
        SessionType $sessionType,
        PlanPhase $phase,
        ?float $raceDistanceM,
        float $coreKm,
        ?array $paces,
        ?int $raceGoalTimeSec = null,
    ): array {
        $isMarathonDistance = WeekPlanBuilder::isMarathonDistance($raceDistanceM);

        return match ($sessionType) {
            SessionType::Rest => [],
            SessionType::Race => self::raceSegments($coreKm, $isMarathonDistance, $paces, $raceGoalTimeSec),
            SessionType::Easy => [self::block(SegmentKey::Main, $coreKm, PaceBand::Easy, $paces)],
            SessionType::Long => self::longSegments($phase, $isMarathonDistance, $coreKm, $paces),
            SessionType::Tempo => self::tempoSegments($phase, $isMarathonDistance, $coreKm, $paces),
            SessionType::Interval => self::intervalSegments($phase, $coreKm, $paces),
        };
    }

    /**
     * Builds the exact persisted coaching decision inside an already-sized
     * whole outing. Excess distance remains explicitly Easy instead of
     * silently becoming more work at intensity.
     *
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     * @return list<SessionSegment>
     */
    public static function forPrescription(
        SessionType $sessionType,
        PlanPhase $phase,
        float $coreKm,
        ?array $paces,
        IntensityPrescription $prescription,
    ): array {
        if ($prescription->isEasy() || ! $sessionType->isQuality()) {
            return self::easyBlock($coreKm, $paces);
        }
        if (TimeTrial::isTrial($prescription->raceContext)) {
            return self::timeTrialSegments($prescription);
        }

        return match (GoalPaceWork::shapeOf($sessionType, $prescription->raceContext)) {
            SessionType::Tempo => self::prescribedTempoSegments($phase, $coreKm, $paces, $prescription),
            SessionType::Interval => self::prescribedIntervalSegments($phase, $coreKm, $paces, $prescription),
            SessionType::Long => self::prescribedLongSegments($coreKm, $paces, $prescription),
            default => self::easyBlock($coreKm, $paces),
        };
    }

    /**
     * The race, as one block at the pace the athlete is racing it at, and nothing else: no warmup
     * carved out and no cooldown prescribed (see `docs/decisions/a-session-is-the-whole-outing.md`).
     * The pace is the goal time over the race distance and the band labels the zone; without a goal
     * time the band's own pace stands in.
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    private static function raceSegments(float $km, bool $isMarathonDistance, ?array $paces, ?int $goalTimeSec): array
    {
        $band = $isMarathonDistance ? PaceBand::Marathon : PaceBand::Threshold;

        if ($goalTimeSec === null || $goalTimeSec <= 0 || $km <= 0.0) {
            return [self::block(SegmentKey::Main, $km, $band, $paces)];
        }

        return [new SessionSegment(
            SegmentKey::Main,
            round($goalTimeSec / 60, 1),
            self::zoneFor($band),
            $band,
            (int) round($goalTimeSec / $km),
            round($km, 1),
        )];
    }

    /**
     * A single Easy-paced block sized at `$originalType`'s own core km, for {@see ReadinessClamp}'s
     * `ModerateOk` downgrade (Tempo/Interval only; no warmup since it is one continuous effort).
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    public static function easyEquivalentOf(SessionType $originalType, float $longRunBaselineKm, float $volumeMultiplier, float $longRunCapKm, ?array $paces): array
    {
        $km = self::coreKmFor($originalType, isPrimaryEasy: false, longRunBaselineKm: $longRunBaselineKm, volumeMultiplier: $volumeMultiplier, longRunCapKm: $longRunCapKm);

        return self::easyBlock($km, $paces);
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    public static function easyBlock(float $km, ?array $paces): array
    {
        return [self::block(SegmentKey::Main, $km, PaceBand::Easy, $paces)];
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    private static function longSegments(PlanPhase $phase, bool $isMarathonDistance, float $km, ?array $paces): array
    {
        return [self::block(SegmentKey::Main, $km, self::longOrTempoPace($phase, $isMarathonDistance), $paces)];
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    private static function tempoSegments(PlanPhase $phase, bool $isMarathonDistance, float $km, ?array $paces): array
    {
        $warmup = self::bookend(SegmentKey::Warmup, self::WARMUP_MINUTES['tempo'], $paces);
        [$blocks, $recoveryMinutes] = self::TEMPO_BLOCK_TABLE[$phase->value] ?? self::TEMPO_BLOCK_TABLE['build'];

        $budgetKm = round(round($km, 1) - ($warmup->km ?? 0.0), 1);
        $pace = self::longOrTempoPace($phase, $isMarathonDistance, forTempo: true);
        $recoveryKm = self::kmFor($recoveryMinutes, PaceBand::Easy, $paces) ?? 0.0;
        $blockKm = round(($budgetKm - ($blocks - 1) * $recoveryKm) / $blocks, 1);

        // One continuous effort at Peak, and on any day too small to break up.
        if ($blocks < 2 || $blockKm <= 0.0) {
            return [$warmup, self::block(SegmentKey::Main, $budgetKm, $pace, $paces)];
        }

        // The closing block takes the rounded remainder, so however the blocks
        // and their recoveries round, the day still adds up to what the card says.
        $segments = [$warmup];
        $spent = 0.0;
        for ($i = 0; $i < $blocks; $i++) {
            $isLast = $i === $blocks - 1;
            $segments[] = self::block(SegmentKey::Main, $isLast ? round($budgetKm - $spent, 1) : $blockKm, $pace, $paces);
            $spent = round($spent + ($isLast ? $budgetKm - $spent : $blockKm), 1);

            if (! $isLast) {
                $recovery = self::bookend(SegmentKey::Recovery, $recoveryMinutes, $paces);
                $segments[] = $recovery;
                $spent = round($spent + ($recovery->km ?? 0.0), 1);
            }
        }

        return $segments;
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    private static function intervalSegments(PlanPhase $phase, float $km, ?array $paces): array
    {
        $warmupMinutes = self::WARMUP_MINUTES['interval'];
        [$repMinutes, $recoveryMinutes] = self::INTERVAL_REP_TABLE[$phase->value] ?? self::INTERVAL_REP_TABLE['build'];

        $budgetKm = $km - self::warmupKm($warmupMinutes, $km, $paces);
        $repKm = self::kmFor($repMinutes, PaceBand::Interval, $paces);
        $recoveryKm = self::kmFor($recoveryMinutes, PaceBand::Easy, $paces);

        $repCount = $repKm === null || $recoveryKm === null
            ? 1
            : max(1, (int) round(($budgetKm + $recoveryKm) / ($repKm + $recoveryKm)));

        $segments = [self::bookend(SegmentKey::Warmup, $warmupMinutes, $paces)];
        for ($i = 0; $i < $repCount; $i++) {
            $segments[] = new SessionSegment(SegmentKey::Interval, $repMinutes, self::zoneFor(PaceBand::Interval), PaceBand::Interval, self::secPerKm(PaceBand::Interval, $paces), $repKm === null ? null : round($repKm, 1));
            if ($i < $repCount - 1) {
                $segments[] = self::bookend(SegmentKey::Recovery, $recoveryMinutes, $paces);
            }
        }

        return $segments;
    }

    /** @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     *  @return list<SessionSegment>
     */
    private static function prescribedTempoSegments(PlanPhase $phase, float $km, ?array $paces, IntensityPrescription $prescription): array
    {
        [$blocks, $recoveryMinutes] = self::TEMPO_BLOCK_TABLE[$phase->value] ?? self::TEMPO_BLOCK_TABLE['build'];
        $warmup = self::bookend(SegmentKey::Warmup, self::WARMUP_MINUTES['tempo'], $paces);
        $recovery = self::bookend(SegmentKey::Recovery, $recoveryMinutes, $paces);
        $baseMinutes = round($prescription->hardMinutes / $blocks, 1);
        $blockMinutes = [...array_fill(0, $blocks - 1, $baseMinutes), $prescription->hardMinutes - ($blocks - 1) * $baseMinutes];
        $closesAtGoal = GoalPaceWork::finalPaceOf($prescription->raceContext) !== null;
        $closingMinutes = $closesAtGoal ? array_map(GoalPaceWork::closingMinutes(...), $blockMinutes) : array_fill(0, $blocks, 0.0);
        $hardKm = self::kmForPrescription($prescription, array_sum($closingMinutes));
        $usedKm = ($warmup->km ?? INF) + $hardKm + ($blocks - 1) * ($recovery->km ?? INF);
        if ($paces !== null && (! is_finite($usedKm) || $usedKm > $km)) {
            return self::easyBlock($km, $paces);
        }

        $segments = [$warmup];
        foreach ($blockMinutes as $i => $minutes) {
            $segments[] = self::prescribedBlock(SegmentKey::Main, $minutes - $closingMinutes[$i], $prescription);
            if ($closesAtGoal) {
                $segments[] = self::prescribedBlock(SegmentKey::Main, $closingMinutes[$i], $prescription, true);
            }
            if ($i < $blocks - 1) {
                $segments[] = $recovery;
            }
        }

        return $paces === null
            ? [...$segments, self::unmeasuredEasy()]
            : self::withEasyRemainder($segments, $km, $paces);
    }

    /** @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     *  @return list<SessionSegment>
     */
    private static function prescribedIntervalSegments(PlanPhase $phase, float $km, ?array $paces, IntensityPrescription $prescription): array
    {
        [$tableRepMinutes, $recoveryMinutes] = self::INTERVAL_REP_TABLE[$phase->value] ?? self::INTERVAL_REP_TABLE['build'];
        $repMinutes = GoalPaceWork::repMinutesOf($prescription->raceContext) ?? $tableRepMinutes;
        $reps = max(1, (int) floor($prescription->hardMinutes / $repMinutes));
        $warmup = self::bookend(SegmentKey::Warmup, self::WARMUP_MINUTES['interval'], $paces);
        $recovery = self::bookend(SegmentKey::Recovery, $recoveryMinutes, $paces);
        $closesAtGoal = GoalPaceWork::finalPaceOf($prescription->raceContext) !== null;
        $closingMinutes = match (true) {
            ! $closesAtGoal => 0.0,
            $reps >= GoalPaceWork::MIN_REPS_FOR_A_GOAL_PACE_REP => $repMinutes,
            default => GoalPaceWork::closingMinutes($repMinutes),
        };
        $hardKm = self::kmForPrescription($prescription, $closingMinutes);
        $usedKm = ($warmup->km ?? INF) + $hardKm + ($reps - 1) * ($recovery->km ?? INF);
        if ($paces !== null && (! is_finite($usedKm) || $usedKm > $km)) {
            return self::easyBlock($km, $paces);
        }

        $segments = [$warmup];
        for ($i = 0; $i < $reps; $i++) {
            $isLast = $i === $reps - 1;
            if ($isLast && $closingMinutes > 0.0 && $closingMinutes < $repMinutes) {
                $segments[] = self::prescribedBlock(SegmentKey::Interval, $repMinutes - $closingMinutes, $prescription);
            }
            $segments[] = self::prescribedBlock(SegmentKey::Interval, $isLast && $closingMinutes > 0.0 ? $closingMinutes : $repMinutes, $prescription, $isLast && $closingMinutes > 0.0);
            if (! $isLast) {
                $segments[] = $recovery;
            }
        }

        return $paces === null
            ? [...$segments, self::unmeasuredEasy()]
            : self::withEasyRemainder($segments, $km, $paces);
    }

    /** @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     *  @return list<SessionSegment>
     */
    private static function prescribedLongSegments(float $km, ?array $paces, IntensityPrescription $prescription): array
    {
        $hardKm = self::kmForPrescription($prescription);
        $fastFinish = FastFinishLongRun::isFastFinish($prescription->raceContext);
        $finishKm = $fastFinish ? 0.0 : self::kmFor(10.0, PaceBand::Easy, $paces) ?? INF;
        if ($paces === null) {
            return [
                self::unmeasuredEasy(),
                self::prescribedBlock(SegmentKey::Main, $prescription->hardMinutes, $prescription),
                ...($fastFinish ? [] : [self::unmeasuredEasy()]),
            ];
        }
        if (! is_finite($finishKm) || $hardKm + $finishKm >= $km) {
            return self::easyBlock($km, $paces);
        }
        if ($fastFinish) {
            return [
                self::block(SegmentKey::Easy, round($km - round($hardKm, 1), 1), PaceBand::Easy, $paces),
                self::prescribedBlock(SegmentKey::Main, $prescription->hardMinutes, $prescription),
            ];
        }

        return [
            self::block(SegmentKey::Easy, round($km - $hardKm - $finishKm, 1), PaceBand::Easy, $paces),
            self::prescribedBlock(SegmentKey::Main, $prescription->hardMinutes, $prescription),
            self::block(SegmentKey::Easy, round($km - round($km - $hardKm - $finishKm, 1) - round($hardKm, 1), 1), PaceBand::Easy, $paces),
        ];
    }

    /**
     * The trial distance as one block at the aim, and nothing else: like race
     * day, the warmup belongs to the athlete and is never carved out.
     *
     * @return list<SessionSegment>
     */
    private static function timeTrialSegments(IntensityPrescription $prescription): array
    {
        $pace = $prescription->paceBand ?? PaceBand::Interval;

        return [new SessionSegment(
            SegmentKey::Main,
            round((int) ($prescription->raceContext['aim_time_sec'] ?? 0) / 60, 1),
            self::zoneFor($pace),
            $pace,
            $prescription->paceSecPerKm,
            round((float) ($prescription->raceContext['distance_m'] ?? 0) / 1000, 1),
        )];
    }

    private static function prescribedBlock(SegmentKey $key, float $minutes, IntensityPrescription $prescription, bool $isFinalUnit = false): SessionSegment
    {
        $pace = $prescription->paceBand;
        $secPerKm = ($isFinalUnit ? GoalPaceWork::finalPaceOf($prescription->raceContext) : null) ?? $prescription->paceSecPerKm;
        if (!$pace instanceof PaceBand) {
            throw new LogicException('A hard prescription requires a pace band.');
        }

        return new SessionSegment(
            $key,
            round($minutes, 1),
            self::zoneFor($pace),
            $pace,
            $secPerKm,
            $secPerKm === null ? null : round($minutes * 60 / $secPerKm, 1),
        );
    }

    private static function kmForPrescription(IntensityPrescription $prescription, float $finalUnitMinutes = 0.0): float
    {
        if ($prescription->paceSecPerKm === null) {
            return INF;
        }
        $finalPace = GoalPaceWork::finalPaceOf($prescription->raceContext);

        return $finalPace === null
            ? $prescription->hardMinutes * 60 / $prescription->paceSecPerKm
            : ($prescription->hardMinutes - $finalUnitMinutes) * 60 / $prescription->paceSecPerKm + $finalUnitMinutes * 60 / $finalPace;
    }

    /** @param list<SessionSegment> $segments
     *  @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     *  @return list<SessionSegment>
     */
    private static function withEasyRemainder(array $segments, float $totalKm, ?array $paces): array
    {
        $spent = self::segmentSumKm($segments);
        if ($spent === null || $spent > $totalKm) {
            return [...$segments, self::unmeasuredEasy()];
        }

        $remainder = round($totalKm - $spent, 1);
        if ($remainder > 0.0) {
            $segments[] = self::block(SegmentKey::Easy, $remainder, PaceBand::Easy, $paces);
        }

        return $segments;
    }

    private static function unmeasuredEasy(): SessionSegment
    {
        return new SessionSegment(SegmentKey::Easy, null, self::zoneFor(PaceBand::Easy), PaceBand::Easy, null);
    }

    /**
     * The warmup's share of the day's budget; zero when there is no VDOT estimate to convert its
     * fixed minutes into distance.
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     */
    private static function warmupKm(float $minutes, float $dayKm, ?array $paces): float
    {
        return min(self::kmFor($minutes, PaceBand::Easy, $paces) ?? 0.0, $dayKm * self::MAX_WARMUP_SHARE);
    }

    /**
     * Distance a fixed-duration segment covers at its own pace — the inverse
     * of {@see self::minutesFor()}.
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     */
    private static function kmFor(float $minutes, PaceBand $pace, ?array $paces): ?float
    {
        $secPerKm = self::secPerKm($pace, $paces);

        return $secPerKm === null ? null : $minutes * 60 / $secPerKm;
    }

    /**
     * A race-simulation pace once the plan is close enough to race day: gated on
     * `Peak/Taper && isMarathonDistance`; Long otherwise falls back to Easy, Tempo to Threshold.
     *
     * @param  bool  $forTempo  selects Tempo's fallback (Threshold) over Long's (Easy)
     */
    private static function longOrTempoPace(PlanPhase $phase, bool $isMarathonDistance, bool $forTempo = false): PaceBand
    {
        $inRacePhase = in_array($phase, [PlanPhase::Peak, PlanPhase::Taper], true);
        if ($forTempo) {
            return $inRacePhase && $isMarathonDistance ? PaceBand::Marathon : PaceBand::Threshold;
        }

        return $inRacePhase && $isMarathonDistance ? PaceBand::Marathon : PaceBand::Easy;
    }

    /** @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces */
    private static function bookend(SegmentKey $key, float $minutes, ?array $paces): SessionSegment
    {
        $km = self::kmFor($minutes, PaceBand::Easy, $paces);

        return new SessionSegment($key, $minutes, self::zoneFor(PaceBand::Easy), PaceBand::Easy, self::secPerKm(PaceBand::Easy, $paces), $km === null ? null : round($km, 1));
    }

    /** @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces */
    private static function block(SegmentKey $key, float $km, PaceBand $pace, ?array $paces): SessionSegment
    {
        return new SessionSegment($key, self::minutesFor($km, $pace, $paces), self::zoneFor($pace), $pace, self::secPerKm($pace, $paces), round($km, 1));
    }

    /** @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces */
    private static function minutesFor(float $km, PaceBand $pace, ?array $paces): ?float
    {
        $secPerKm = self::secPerKm($pace, $paces);

        return $secPerKm === null ? null : round($km * $secPerKm / 60, 1);
    }

    /** @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces */
    private static function secPerKm(PaceBand $pace, ?array $paces): ?int
    {
        return $paces[$pace->value] ?? null;
    }

    /**
     * Qualitative %HRR zone a pace target implies: Threshold sits at the top of the aerobic zone,
     * Interval is VO2max-territory, Easy is the conversational zone.
     */
    private static function zoneFor(PaceBand $pace): string
    {
        return match ($pace) {
            PaceBand::Easy => HeartRateZones::KEYS[1], // Z2
            PaceBand::Marathon => HeartRateZones::KEYS[2], // Z3
            PaceBand::Threshold => HeartRateZones::KEYS[3], // Z4
            PaceBand::Interval => HeartRateZones::KEYS[4], // Z5
        };
    }
}
