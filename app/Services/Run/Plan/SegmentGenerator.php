<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Metrics\HeartRateZones;

/**
 * Turns a day's `(session_type, phase)` into its full ordered list of
 * {@see SessionSegment}s — warmup, main effort, interval reps —
 * combining the athlete's CURRENT long-run baseline, phase-derived volume
 * multiplier and VDOT-derived paces. Render-time only, same as the
 * retired distance-band lookup before it: nothing here is stored on a row,
 * so a week rendered weeks after it was generated still reflects fitness gained
 * since (see `docs/features/plan-periodizer.md`). `WeekPlanBuilder` only
 * ever decides `session_type`/`phase`; every number below is computed here,
 * fresh, every render.
 *
 * A day's distance is the WHOLE outing, warmup included — {@see self::kmFor()}
 * carves the fixed-duration warmup (and an Interval day's recovery jogs) out of
 * that budget rather than adding them on top of it, so what the card says and
 * what {@see SessionMatcher} grades are the same run. A cooldown is never
 * prescribed. See `docs/decisions/a-session-is-the-whole-outing.md`.
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
     * A warmup never eats more than half its own session. The bookend is a
     * fixed duration while the day's budget scales with the athlete, so on a
     * taper week at the {@see TrainingBaseline} long-run floor the warmup
     * would otherwise exceed the whole day and leave nothing to run.
     */
    private const float MAX_WARMUP_SHARE = 0.5;

    /**
     * Rep length / recovery length by phase, minutes. Interval only ever
     * occurs in Build and in Peak/Taper for a non-marathon-distance race
     * (see {@see WeekPlanBuilder::phaseQualitySlots()}) — Build introduces
     * structured VO2max work, Peak sustains longer race-specific reps, Taper
     * sharpens with short reps and generous recovery so the athlete arrives
     * fresh rather than fatigued.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    /**
     * Threshold blocks / recovery length by phase, minutes. Interval work
     * already developed across a season through {@see self::INTERVAL_REP_TABLE}
     * while a tempo day never changed shape — it was always one continuous
     * block, growing only as weekly volume grew. A coach progresses threshold
     * work the other way: several shorter blocks early, fewer and longer as the
     * athlete adapts, one continuous effort at Peak. Taper breaks it up again so
     * the session sharpens without draining.
     *
     * @var array<string, array{0: int, 1: float}>
     */
    private const array TEMPO_BLOCK_TABLE = [
        'base' => [3, 2.0],
        'build' => [2, 2.0],
        'peak' => [1, 0.0],
        'taper' => [2, 2.0],
    ];

    private const array INTERVAL_REP_TABLE = [
        'build' => [3.0, 2.0],
        'peak' => [4.0, 2.0],
        'taper' => [2.0, 3.0],
    ];

    /**
     * The WHOLE distance this session asks for, warmup included, before any
     * redistribution scale — the direct replacement for the retired
     * distance-band lookup. Pace-independent by construction, so
     * {@see VolumeRedistributor} can sum a week without paces and an athlete
     * with no VDOT estimate still gets a number.
     */
    public static function coreKmFor(SessionType $sessionType, bool $isPrimaryEasy, float $longRunBaselineKm, float $volumeMultiplier): float
    {
        if ($sessionType === SessionType::Rest) {
            return 0.0;
        }

        $effectiveLong = $longRunBaselineKm * $volumeMultiplier;

        return match ($sessionType) {
            SessionType::Long => round($effectiveLong, 1),
            SessionType::Tempo => round($effectiveLong * self::MEDIUM_FRACTION_OF_LONG, 1),
            SessionType::Interval => round($effectiveLong * self::SHORT_FRACTION_OF_LONG, 1),
            SessionType::Easy => round($effectiveLong * ($isPrimaryEasy ? self::MEDIUM_FRACTION_OF_LONG : self::SHORT_FRACTION_OF_LONG), 1),
        };
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces  seconds per kilometre; null when the athlete has no VDOT estimate yet
     * @param  float  $volumeScale  from {@see VolumeRedistributor} — 1.0 outside a redistributed week
     * @return list<SessionSegment>
     */
    public static function generate(
        SessionType $sessionType,
        PlanPhase $phase,
        bool $isMarathonDistance,
        bool $isPrimaryEasy,
        float $longRunBaselineKm,
        float $volumeMultiplier,
        ?array $paces,
        float $volumeScale = 1.0,
    ): array {
        if ($sessionType === SessionType::Rest) {
            return [];
        }

        $coreKm = self::coreKmFor($sessionType, $isPrimaryEasy, $longRunBaselineKm, $volumeMultiplier) * $volumeScale;

        return match ($sessionType) {
            SessionType::Easy => [self::block(SegmentKey::Main, $coreKm, PaceBand::Easy, $paces)],
            SessionType::Long => self::longSegments($phase, $isMarathonDistance, $coreKm, $paces),
            SessionType::Tempo => self::tempoSegments($phase, $isMarathonDistance, $coreKm, $paces),
            SessionType::Interval => self::intervalSegments($phase, $coreKm, $paces),
        };
    }

    /**
     * A single Easy-paced block sized at whatever `$originalType`'s own core
     * km would have been — {@see ReadinessClamp}'s `ModerateOk` downgrade
     * (Tempo/Interval only: keeps the day's original size, just re-paced to
     * Easy, no warmup since it's a single continuous effort now).
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    public static function easyEquivalentOf(SessionType $originalType, float $longRunBaselineKm, float $volumeMultiplier, ?array $paces): array
    {
        $km = self::coreKmFor($originalType, isPrimaryEasy: false, longRunBaselineKm: $longRunBaselineKm, volumeMultiplier: $volumeMultiplier);

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

    /**
     * The warmup's share of the day's budget — zero when there is no VDOT
     * estimate to convert its fixed minutes into distance, which leaves the
     * whole day to the main work exactly as it was before this became the
     * whole outing.
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
     * A race-simulation pace once the plan is close enough to race day that
     * race-pace-specific work makes sense — mirrors the choice
     * {@see WeekPlanBuilder} made for `long`/`tempo` before this class
     * existed. Both are gated on the identical `Peak/Taper && isMarathonDistance`
     * condition; they differ only in their non-race-pace fallback (Long
     * falls back to Easy, Tempo to Threshold).
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
     * Qualitative %HRR zone a pace target implies. No existing convention
     * ties {@see PaceBand} to {@see HeartRateZones} — this is new work, a
     * standard physiological mapping: Threshold sits at the top of the
     * aerobic zone, Interval is VO2max-territory, Easy is the classic
     * "conversational" zone most training guidance means by "easy".
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
