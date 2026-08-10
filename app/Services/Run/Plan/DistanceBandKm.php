<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\DistanceBand;

/**
 * Converts a stored qualitative {@see DistanceBand} into a displayable km
 * figure, given the athlete's CURRENT long-run baseline
 * ({@see TrainingBaseline}) and a phase-derived volume multiplier
 * ({@see PhaseSchedule::volumeMultipliers()}). Render-time only — the row
 * itself never stores a km number, so a regenerated (or simply re-rendered)
 * week stays honest against the athlete's current fitness rather than
 * whatever it looked like when the row was generated.
 */
final class DistanceBandKm
{
    /** Medium/Short scale proportionally under the week's Long run. */
    private const float MEDIUM_FRACTION_OF_LONG = 0.65;

    private const float SHORT_FRACTION_OF_LONG = 0.40;

    public static function kmFor(DistanceBand $band, float $longRunBaselineKm, float $volumeMultiplier): float
    {
        if ($band === DistanceBand::Rest) {
            return 0.0;
        }

        $effectiveLong = $longRunBaselineKm * $volumeMultiplier;

        return match ($band) {
            DistanceBand::Long => round($effectiveLong, 1),
            DistanceBand::Medium => round($effectiveLong * self::MEDIUM_FRACTION_OF_LONG, 1),
            DistanceBand::Short => round($effectiveLong * self::SHORT_FRACTION_OF_LONG, 1),
        };
    }
}
