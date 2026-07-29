<?php

declare(strict_types=1);

namespace App\Services\AI\Context;

use NoDiscard;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Shared activity signals that more than one narrator feeds into its LLM
 * context (distance, decoupling, splits, zones, weather). Built once per
 * narration call so the per-narrator context arrays stay byte-identical
 * while the field extraction lives in one place. Narrator-specific keys
 * (mood, PR flags, cadence, rarity, ...) stay in the narrators.
 */
final readonly class ActivityNarrationContext
{
    public function __construct(
        public ?float $distanceMeters,
        /** Cardiac drift over the run, or null when it carries no reading. */
        public ?float $decouplingPct,
        /** Whether the second half was faster, or null when there are no splits. */
        public ?bool $negativeSplit,
        /** @var array<string, float|int> Time-in-zone percentages keyed by zone label. */
        public array $zonePct,
        public ?int $weatherTempC,
        public ?bool $weatherRain,
        public ?int $weatherWindSpeedKmh,
        public ?int $weatherWindGustKmh,
        public ?int $weatherWindDirectionDeg,
        /**
         * 'forecast' when the rain flag came from the (uncertain) forecast
         * endpoint, 'observed' when it was measured, and null when the run
         * carries no rain reading at all — which is not the same as no rain.
         */
        public ?string $weatherRainSource,
    ) {
    }

    #[NoDiscard]
    public static function fromDetail(?ActivityDetail $detail): self
    {
        $summary = StreamSummary::fromArray($detail?->streamSummary());

        return new self(
            distanceMeters: $detail?->distance,
            decouplingPct: $summary->decouplingPct(),
            negativeSplit: $summary->negativeSplit(),
            zonePct: $summary->zonePct(),
            weatherTempC: $detail?->weather_temp_c,
            weatherRain: $detail?->weather_rain_detected,
            weatherWindSpeedKmh: $detail?->weather_wind_speed_kmh,
            weatherWindGustKmh: $detail?->weather_wind_gust_kmh,
            weatherWindDirectionDeg: $detail?->weather_wind_direction_deg,
            // No rain reading at all is not an observation of no rain: without
            // this branch a run that never had weather attached reported its
            // source as "observed", which the prompts are entitled to treat as
            // fact.
            weatherRainSource: match (true) {
                $detail?->weather_rain_detected === null => null,
                (bool) $detail->weather_rain_is_forecast => 'forecast',
                default => 'observed',
            },
        );
    }

    /**
     * Distance in kilometres rounded to the given precision; a missing
     * distance counts as 0.
     */
    public function distanceKm(int $precision): float
    {
        return round(((float) ($this->distanceMeters ?? 0)) / 1000, $precision);
    }

    /**
     * Distance in kilometres rounded to the given precision, or null when
     * the distance is unknown.
     */
    public function distanceKmOrNull(int $precision): ?float
    {
        return $this->distanceMeters !== null
            ? round($this->distanceMeters / 1000, $precision)
            : null;
    }
}
