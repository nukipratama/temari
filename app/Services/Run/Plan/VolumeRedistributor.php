<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

/**
 * Current-week easy runs are projected at render time on Home and Plan.
 * Completed volume, pinned sessions, today's fixed session and future key
 * sessions are reserved before remaining easy days are scaled. Long, tempo
 * and interval days never resize. Missed volume is not carried into later
 * runs, while actual surplus may shrink them. Never mutates stored rows.
 *
 * Redistribution never exceeds {@see self::MAX_SCALE}, so missed volume is
 * written off rather than added to later runs. It is floored at
 * {@see self::MIN_SCALE} when earlier runs already exceed their menu.
 *
 * Nothing here crosses a week boundary: an over-run reshapes only the days
 * left in the week it happened in, and the following week is built from its
 * own arc position.
 */
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
