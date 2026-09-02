<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

/**
 * "The week's volume redistributes rather than being lost" — a render-time
 * recompute, not a specific-day-swap heuristic. Any Plan-page read
 * recomputes the remaining unpinned, non-past training days of the current
 * week from (this week's volume target - already completed - pinned days'
 * volume), spread across those days by a single scale factor applied to each
 * day's own core km ({@see SegmentGenerator::coreKmFor()}) — continuous, not
 * bucketed to a small set of discrete sizes, since {@see SegmentGenerator}
 * already takes an arbitrary target distance for any session type. A
 * readiness-clamped day's lost volume folds in automatically, since the
 * caller excludes it from `$eligibleDaysKm` and its now-fixed (clamped)
 * output from the target the same way a completed run would be. Never
 * mutates stored rows.
 *
 * Redistribution is capped at {@see self::MAX_SCALE}: past that the week's
 * remaining volume is written off rather than crammed.
 */
final class VolumeRedistributor
{
    /**
     * Ceiling on how far missed volume may inflate the days that remain. A
     * week missed until Friday would otherwise land its whole target on two
     * days, which is exactly the cram week the conservative clamps exist to
     * prevent; volume past this cap is dropped rather than carried.
     */
    public const float MAX_SCALE = 1.35;

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

        $scale = min(self::MAX_SCALE, max(0.0, $remainingTargetKm) / $originalTotalKm);

        return array_fill_keys(array_keys($trainingDaysKm), $scale);
    }
}
