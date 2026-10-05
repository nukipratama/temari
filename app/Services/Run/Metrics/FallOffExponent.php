<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\RaceSupport;
use Illuminate\Support\Carbon;

/**
 * How much an athlete slows as the distance grows: the k in T₂ = T₁·(D₂/D₁)^k.
 *
 * Fitted only within a cluster of efforts run close together in time, so a
 * change in fitness between two dates is never read as fall-off.
 */
final class FallOffExponent
{
    public const float MIN = 1.06;

    public const float MAX = 1.15;

    public const int CLUSTER_WEEKS = 8;

    public const float MIN_DISTANCE_SPREAD = 1.5;

    public const float DEFAULT_UP_TO_10K = 1.08;

    public const float DEFAULT_HALF = 1.10;

    public const float DEFAULT_MARATHON = 1.15;

    private const float TEN_K_METERS = 10_000.0;

    public static function default(float $distanceM): float
    {
        return match (true) {
            $distanceM <= self::TEN_K_METERS => self::DEFAULT_UP_TO_10K,
            $distanceM <= RaceSupport::MARATHON_CLASS_ABOVE_M => self::DEFAULT_HALF,
            default => self::DEFAULT_MARATHON,
        };
    }

    /**
     * The clamped slope of log time over log distance for the newest valid
     * cluster, or null when no cluster spans enough distance.
     *
     * @param list<array{date: Carbon, distance_m: float, time_sec: float}> $efforts
     */
    public static function fit(array $efforts): ?float
    {
        usort($efforts, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        foreach ($efforts as $newest) {
            $from = $newest['date']->copy()->subWeeks(self::CLUSTER_WEEKS);
            $cluster = array_values(array_filter(
                $efforts,
                static fn (array $effort): bool => $effort['date']->betweenIncluded($from, $newest['date']),
            ));
            $distances = array_column($cluster, 'distance_m');
            if (count($distances) < 2 || max($distances) / min($distances) < self::MIN_DISTANCE_SPREAD) {
                continue;
            }

            return max(self::MIN, min(self::MAX, self::slope($cluster)));
        }

        return null;
    }

    /** @param list<array{date: Carbon, distance_m: float, time_sec: float}> $cluster */
    private static function slope(array $cluster): float
    {
        $xs = array_map(static fn (array $effort): float => log($effort['distance_m']), $cluster);
        $ys = array_map(static fn (array $effort): float => log($effort['time_sec']), $cluster);
        $meanX = array_sum($xs) / count($xs);
        $meanY = array_sum($ys) / count($ys);
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($xs as $i => $x) {
            $covariance += ($x - $meanX) * ($ys[$i] - $meanY);
            $variance += ($x - $meanX) ** 2;
        }

        return $covariance / $variance;
    }
}
