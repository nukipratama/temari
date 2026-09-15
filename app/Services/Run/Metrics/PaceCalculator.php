<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

final class PaceCalculator
{
    /**
     * Pace in seconds per kilometre. Null when distance or time is missing or non-positive.
     */
    public static function secPerKm(?float $distanceMeters, int|float|null $seconds): ?float
    {
        if ($distanceMeters === null || $distanceMeters <= 0 || $seconds === null || $seconds <= 0) {
            return null;
        }

        return $seconds / ($distanceMeters / 1000);
    }
}
