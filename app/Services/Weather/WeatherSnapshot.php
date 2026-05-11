<?php

declare(strict_types=1);

namespace App\Services\Weather;

/**
 * Point-in-time weather at a run's start. Immutable value object so the
 * ingest pipeline can pass it around without anyone mutating in flight.
 */
final readonly class WeatherSnapshot
{
    public function __construct(
        public int $tempC,
        public int $humidityPct,
        public bool $rainDetected,
    ) {
    }
}
