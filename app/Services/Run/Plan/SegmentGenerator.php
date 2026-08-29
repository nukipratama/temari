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
 * {@see SessionSegment}s — warmup, main effort, interval reps, cooldown —
 * combining the athlete's CURRENT long-run baseline, phase-derived volume
 * multiplier and VDOT-derived paces. Render-time only, same as
 * {@see DistanceBandKm} before it: nothing here is stored on a row, so a
 * week rendered weeks after it was generated still reflects fitness gained
 * since (see `docs/features/plan-periodizer.md`). `WeekPlanBuilder` only
 * ever decides `session_type`/`phase`; every number below is computed here,
 * fresh, every render.
 *
 * Warmup/cooldown are fixed-duration bookends (physiological readiness, not
 * volume) and are never scaled by `$volumeScale`. The core distance — the
 * main block on Easy/Long/Tempo, the aggregate rep budget on Interval — is
 * everything `$volumeScale` (from {@see VolumeRedistributor}) touches.
 */
final class SegmentGenerator
{
    /** Medium/Short scale proportionally under the week's Long run — the same fractions {@see DistanceBandKm} used. */
    private const float MEDIUM_FRACTION_OF_LONG = 0.65;

    private const float SHORT_FRACTION_OF_LONG = 0.40;

    /** @var array<string, array{0: float, 1: float}> session_type value => [warmup minutes, cooldown minutes] */
    private const array WARMUP_COOLDOWN = [
        'tempo' => [10.0, 5.0],
        'interval' => [12.0, 8.0],
    ];

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
    private const array INTERVAL_REP_TABLE = [
        'build' => [3.0, 2.0],
        'peak' => [4.0, 2.0],
        'taper' => [2.0, 3.0],
    ];

    /**
     * The core (pre-warmup/cooldown) distance this session's main work
     * targets, before any redistribution scale — the direct replacement for
     * {@see DistanceBandKm::kmFor()}'s band lookup. Exposed separately so
     * {@see VolumeRedistributor} can sum a week's original km without
     * needing paces (it only ever compares distances, never converts to
     * minutes).
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
     * Easy, no warmup/cooldown structure since it's a single continuous
     * effort now).
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
        [$warmupMinutes, $cooldownMinutes] = self::WARMUP_COOLDOWN['tempo'];

        return [
            self::bookend(SegmentKey::Warmup, $warmupMinutes, $paces),
            self::block(SegmentKey::Main, $km, self::longOrTempoPace($phase, $isMarathonDistance, forTempo: true), $paces),
            self::bookend(SegmentKey::Cooldown, $cooldownMinutes, $paces),
        ];
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<SessionSegment>
     */
    private static function intervalSegments(PlanPhase $phase, float $km, ?array $paces): array
    {
        [$warmupMinutes, $cooldownMinutes] = self::WARMUP_COOLDOWN['interval'];
        [$repMinutes, $recoveryMinutes] = self::INTERVAL_REP_TABLE[$phase->value] ?? self::INTERVAL_REP_TABLE['build'];

        $workMinutes = self::minutesFor($km, PaceBand::Interval, $paces);
        $repCount = $workMinutes === null ? 1 : max(1, (int) round($workMinutes / $repMinutes));

        $segments = [self::bookend(SegmentKey::Warmup, $warmupMinutes, $paces)];
        for ($i = 0; $i < $repCount; $i++) {
            $segments[] = new SessionSegment(SegmentKey::Interval, $repMinutes, self::zoneFor(PaceBand::Interval), PaceBand::Interval, self::secPerKm(PaceBand::Interval, $paces));
            if ($i < $repCount - 1) {
                $segments[] = self::bookend(SegmentKey::Recovery, $recoveryMinutes, $paces);
            }
        }
        $segments[] = self::bookend(SegmentKey::Cooldown, $cooldownMinutes, $paces);

        return $segments;
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
        return new SessionSegment($key, $minutes, self::zoneFor(PaceBand::Easy), PaceBand::Easy, self::secPerKm(PaceBand::Easy, $paces));
    }

    /** @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces */
    private static function block(SegmentKey $key, float $km, PaceBand $pace, ?array $paces): SessionSegment
    {
        return new SessionSegment($key, self::minutesFor($km, $pace, $paces), self::zoneFor($pace), $pace, self::secPerKm($pace, $paces));
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
