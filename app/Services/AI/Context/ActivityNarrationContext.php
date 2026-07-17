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
        /** Raw `decoupling_pct` from the stream summary, passed through untyped. */
        public mixed $decouplingPct,
        /** Raw `negative_split` from the stream summary, passed through untyped. */
        public mixed $negativeSplit,
        /** @var array<string, float|int> Time-in-zone percentages keyed by zone label. */
        public array $zonePct,
        public ?int $weatherTempC,
        public ?bool $weatherRain,
        public ?int $weatherWindSpeedKmh,
        public ?int $weatherWindGustKmh,
        public ?int $weatherWindDirectionDeg,
        /** 'forecast' when the rain flag is from the (uncertain) forecast endpoint, else 'observed'. */
        public string $weatherRainSource,
    ) {
    }

    #[NoDiscard]
    public static function fromDetail(?ActivityDetail $detail): self
    {
        $summary = $detail?->streamSummary() ?? [];

        return new self(
            distanceMeters: $detail?->distance,
            decouplingPct: $summary['decoupling_pct'] ?? null,
            negativeSplit: $summary['negative_split'] ?? null,
            zonePct: StreamSummary::zonePct($summary),
            weatherTempC: $detail?->weather_temp_c,
            weatherRain: $detail?->weather_rain_detected,
            weatherWindSpeedKmh: $detail?->weather_wind_speed_kmh,
            weatherWindGustKmh: $detail?->weather_wind_gust_kmh,
            weatherWindDirectionDeg: $detail?->weather_wind_direction_deg,
            weatherRainSource: $detail?->weather_rain_is_forecast ? 'forecast' : 'observed',
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
