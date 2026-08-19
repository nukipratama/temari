<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

enum HrProfile: string
{
    case Z2Steady = 'z2_steady';
    case Tempo = 'tempo';
    case Intervals = 'intervals';
    case LsdDrift = 'lsd_drift';
    case NegSplit = 'neg_split';
    // A genuinely middling run: HR straddles Z2/Z3 so no zone dominates — lands
    // the "Easy Run" default move that no other profile reaches.
    case Mixed = 'mixed';
    // A hard, even time-trial: HR sits in Z4 (so Z3 share stays low) with no
    // negative split — the recipe for a "New Record" PR that is not "Tempo Lock".
    case HardEven = 'hard_even';

    public function velocityMultiplier(float $progress, bool $intervalWork): float
    {
        return match ($this) {
            self::NegSplit => $progress < 0.5 ? 0.96 : 1.07,
            self::Intervals => $intervalWork ? 1.30 : 0.70,
            self::LsdDrift => 1.04 - 0.08 * $progress,
            self::Mixed => 1.02 - 0.04 * $progress,
            self::HardEven => 1.01 - 0.02 * $progress,
            self::Tempo, self::Z2Steady => 1.0,
        };
    }

    public function hrBase(float $progress, bool $intervalWork): float
    {
        return match ($this) {
            self::Z2Steady => 148.0,
            self::Tempo => 164.0,
            self::Intervals => $intervalWork ? 174.0 : 138.0,
            self::LsdDrift => 145.0 + 22.0 * $progress,
            self::NegSplit => $progress < 0.5 ? 150.0 : 162.0 + 12.0 * ($progress - 0.5) * 2,
            self::Mixed => 146.0 + 12.0 * $progress,
            self::HardEven => 168.0 + 4.0 * $progress,
        };
    }

    public function cadenceDrift(float $progress): float
    {
        return match ($this) {
            self::LsdDrift => -3.0 * $progress,
            self::NegSplit => 4.0 * $progress,
            self::Intervals => 0.0,
            self::Mixed => -2.5 * $progress,
            self::HardEven => -1.5 * $progress,
            self::Tempo, self::Z2Steady => -1.0 * $progress,
        };
    }
}
