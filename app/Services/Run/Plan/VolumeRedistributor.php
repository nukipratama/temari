<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

/** Future easy mileage may shrink but never increases to replace missed running. */
final class VolumeRedistributor
{
    public const float MAX_SCALE = 1.0;

    public const float MIN_SCALE = 0.7;

    /**
     * @param  array<string, float>  $eligibleDaysKm  date => original core km, for the week's remaining unpinned non-past training days
     * @return array<string, float>  date => volume scale, for {@see SegmentGenerator::generate()}'s `$volumeScale`
     */
    public static function redistribute(array $eligibleDaysKm, float $remainingTargetKm): array
    {
        $trainingDaysKm = array_filter($eligibleDaysKm, static fn (float $km): bool => $km > 0.0);
        if ($trainingDaysKm === []) {
            return [];
        }

        $originalTotalKm = array_sum($trainingDaysKm);
        if ($originalTotalKm <= 0.0) {
            return [];
        }

        $scale = min(self::MAX_SCALE, max(self::MIN_SCALE, max(0.0, $remainingTargetKm) / $originalTotalKm));

        return array_fill_keys(array_keys($trainingDaysKm), $scale);
    }
}
