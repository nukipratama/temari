<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

final class PaceCalculator
{
    /**
     * Pace in seconds per kilometre. Null when distance or time is missing or non-positive.
     */
    public static function secPerKm(?float $distanceMeters, int|float|null $movingTimeSec): ?float
    {
        if ($distanceMeters === null || $distanceMeters <= 0 || $movingTimeSec === null || $movingTimeSec <= 0) {
            return null;
        }

        return $movingTimeSec / ($distanceMeters / 1000);
    }
}
