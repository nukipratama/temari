<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\ActivityDetail;

final class RunDistanceTimes
{
    /**
     * The run's fastest time over every standard distance it covers, keyed by category value.
     *
     * @return array<string, float>
     */
    public static function forDetail(ActivityDetail $detail): array
    {
        $distance = (float) ($detail->distance ?? 0);
        $splits = self::splitRows(StreamSummary::fromArray($detail->streamSummary()));
        $values = [];

        foreach (PrCategory::distances() as $category) {
            $targetMeters = $category->distanceMeters();
            if ($targetMeters === null || $distance < $targetMeters * 0.99) {
                continue;
            }
            $value = self::timeAtDistance($splits, $targetMeters);
            if ($value !== null && $value > 0) {
                $values[$category->value] = $value;
            }
        }

        return $values;
    }

    /**
     * Fastest time over any contiguous window of splits that covers the target
     * distance, so a negative-split run records its genuine best embedded effort
     * rather than only its opening segment. Null when no window reaches the target.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    public static function timeAtDistance(array $splits, float $targetMeters): ?float
    {
        $best = null;
        $count = count($splits);

        for ($start = 0; $start < $count; $start++) {
            $window = self::windowTime(array_slice($splits, $start), $targetMeters);
            if ($window !== null && ($best === null || $window < $best)) {
                $best = $window;
            }
        }

        return $best;
    }

    /**
     * The run's segments in order: every full kilometre, then the trailing
     * sub-km leftover. The window needs that leftover to reach a target that
     * lands inside it — a 42.6 km run only covers 42 full kilometres, so the
     * marathon PR sits in the final 600 m. The leftover's time is recovered
     * from its already-normalized pace, the one place Strava's `moving_time`
     * still shows through until KmSplitBuilder derives the partial itself.
     *
     * @return list<array<string, mixed>>
     */
    private static function splitRows(StreamSummary $summary): array
    {
        $rows = array_values($summary->perKm() ?? []);

        $partial = $summary->partialSplit() ?? [];
        $pace = $partial['pace'] ?? null;
        $paceSec = is_string($pace) ? PaceFormatter::parse($pace) : null;
        $distance = (float) ($partial['distance_m'] ?? 0);
        if ($paceSec !== null && $distance > 0) {
            $rows[] = ['distance_m' => $distance, 'elapsed_sec' => $paceSec * $distance / 1000];
        }

        return $rows;
    }

    /**
     * Time to cover the target distance from the first split onward, interpolating
     * within the final partial split. Null when the given splits fall short.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    private static function windowTime(array $splits, float $targetMeters): ?float
    {
        $accDist = 0.0;
        $accTime = 0.0;
        foreach ($splits as $split) {
            $distance = (float) ($split['distance_m'] ?? 0);
            $time = (float) ($split['elapsed_sec'] ?? 0);
            if ($distance <= 0 || $time <= 0) {
                continue;
            }
            if ($accDist + $distance >= $targetMeters) {
                $remaining = $targetMeters - $accDist;

                return $accTime + $time * ($remaining / $distance);
            }
            $accDist += $distance;
            $accTime += $time;
        }

        return null;
    }
}
