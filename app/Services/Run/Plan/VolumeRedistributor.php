<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\DistanceBand;

/**
 * "The week's volume redistributes rather than being lost" — a render-time
 * recompute, not a specific-day-swap heuristic. Any Plan-page read
 * recomputes the remaining unpinned, non-past training days of the current
 * week from (this week's volume target - already completed - pinned days'
 * volume), spread across those days by their existing relative weighting
 * (their original band's share of km). A readiness-clamped day's lost volume
 * folds in automatically, since the caller excludes it from `$eligibleDays`
 * and its now-fixed (clamped) output from the target the same way a
 * completed run would be. Never mutates stored rows.
 *
 * Redistribution is capped at {@see self::MAX_SCALE}: past that the week's
 * remaining volume is written off rather than crammed.
 */
final class VolumeRedistributor
{
    /** @var list<DistanceBand> nearest-band bucketing order, smallest first */
    private const array BUCKETS = [DistanceBand::Short, DistanceBand::Medium, DistanceBand::Long];

    /**
     * Ceiling on how far missed volume may inflate the days that remain. A
     * week missed until Friday would otherwise land its whole target on two
     * days, which is exactly the cram week the conservative clamps exist to
     * prevent; volume past this cap is dropped rather than carried.
     */
    public const float MAX_SCALE = 1.35;

    /**
     * @param  array<string, DistanceBand>  $eligibleDays  date => original band, for the week's remaining unpinned non-past training days
     * @param  array<string, float>  $bandKm  DistanceBand value => km, from {@see DistanceBandKm} for this week
     * @return array<string, DistanceBand>  date => redistributed band
     */
    public static function redistribute(array $eligibleDays, float $remainingTargetKm, array $bandKm): array
    {
        $trainingDays = array_filter($eligibleDays, static fn (DistanceBand $band): bool => $band !== DistanceBand::Rest);
        if ($trainingDays === []) {
            return $eligibleDays;
        }

        $originalTotalKm = array_sum(array_map(
            static fn (DistanceBand $band): float => $bandKm[$band->value] ?? 0.0,
            $trainingDays,
        ));
        if ($originalTotalKm <= 0.0) {
            return $eligibleDays;
        }

        $scale = min(self::MAX_SCALE, max(0.0, $remainingTargetKm) / $originalTotalKm);

        $result = $eligibleDays;
        foreach ($trainingDays as $date => $originalBand) {
            $scaledKm = ($bandKm[$originalBand->value] ?? 0.0) * $scale;
            $result[$date] = self::nearestBand($scaledKm, $bandKm);
        }

        return $result;
    }

    /**
     * @param  array<string, float>  $bandKm
     */
    private static function nearestBand(float $km, array $bandKm): DistanceBand
    {
        $best = DistanceBand::Short;
        $bestDiff = null;
        foreach (self::BUCKETS as $band) {
            $diff = abs(($bandKm[$band->value] ?? 0.0) - $km);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $band;
            }
        }

        return $best;
    }
}
