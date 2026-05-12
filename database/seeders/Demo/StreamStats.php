<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use function count;

/**
 * Stream-array math the demo seeder needs. `mean` / `max` skip non-scalar
 * entries so it stays safe over heterogeneous Strava-shaped payloads
 * (the `latlng` stream is a list of [lat, lng] pairs, for instance).
 */
final class StreamStats
{
    /**
     * @param  list<int|float|array{float, float}>  $stream
     */
    public static function mean(array $stream): ?float
    {
        $scalars = array_filter($stream, static fn ($v): bool => is_int($v) || is_float($v));
        if ($scalars === []) {
            return null;
        }

        return round(array_sum($scalars) / count($scalars), 1);
    }

    /**
     * @param  list<int|float|array{float, float}>  $stream
     */
    public static function max(array $stream): ?float
    {
        $scalars = array_filter($stream, static fn ($v): bool => is_int($v) || is_float($v));
        if ($scalars === []) {
            return null;
        }

        return (float) max($scalars);
    }

    /**
     * Average of $stream over the inclusive [$startIdx, $endIdx] window.
     * Returns 0.0 when the window has no scalar samples — splits math
     * relies on this so a missing-HR run still produces a row shape.
     *
     * @param  list<int|float|array{float, float}>  $stream
     */
    public static function sliceMean(array $stream, int $startIdx, int $endIdx): float
    {
        $sum = 0.0;
        $count = 0;
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $value = $stream[$i] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }
            $sum += (float) $value;
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }
}
