<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * Profiles the demo synthesizer can shape a run against. Each case owns
 * its own velocity / HR / cadence curves so adding a 6th profile is a
 * single-file change instead of hunting parallel match arms.
 *
 *   Z2Steady    flat aerobic effort, hr in low Z2
 *   Tempo       sustained Z3 / low Z4
 *   Intervals   alternating Z2 / Z4 segments
 *   LsdDrift    long Z2 with hr drifting up the back half
 *   NegSplit    first half ~5% slower, second half rises, hr climbs
 */
enum HrProfile: string
{
    case Z2Steady = 'z2_steady';
    case Tempo = 'tempo';
    case Intervals = 'intervals';
    case LsdDrift = 'lsd_drift';
    case NegSplit = 'neg_split';

    /**
     * Per-sample velocity multiplier applied to the run's average speed.
     */
    public function velocityMultiplier(float $progress, bool $intervalWork): float
    {
        return match ($this) {
            self::NegSplit => $progress < 0.5 ? 0.96 : 1.07,
            self::Intervals => $intervalWork ? 1.30 : 0.70,
            self::LsdDrift => 1.04 - 0.08 * $progress,
            self::Tempo, self::Z2Steady => 1.0,
        };
    }

    /**
     * Baseline HR (bpm) before per-sample noise.
     */
    public function hrBase(float $progress, bool $intervalWork): float
    {
        return match ($this) {
            self::Z2Steady => 148.0,
            self::Tempo => 164.0,
            self::Intervals => $intervalWork ? 174.0 : 138.0,
            self::LsdDrift => 145.0 + 22.0 * $progress,
            self::NegSplit => $progress < 0.5 ? 150.0 : 162.0 + 12.0 * ($progress - 0.5) * 2,
        };
    }

    /**
     * Cadence drift (spm) over the run on top of the blueprint's base spm.
     * Captures end-of-run fatigue (LSD), late-run pick-up (negative split),
     * or no drift (intervals — velocity already cycles).
     */
    public function cadenceDrift(float $progress): float
    {
        return match ($this) {
            self::LsdDrift => -3.0 * $progress,
            self::NegSplit => 4.0 * $progress,
            self::Intervals => 0.0,
            self::Tempo, self::Z2Steady => -1.0 * $progress,
        };
    }
}
