<?php

declare(strict_types=1);

namespace App\Services\Weather;

final readonly class WeatherSnapshot
{
    public function __construct(
        public int $tempC,
        public int $humidityPct,
        public bool $rainDetected,
        public ?int $windSpeedKmh = null,
        public ?int $windGustKmh = null,
        public ?int $windDirectionDeg = null,
        public bool $rainIsForecast = false,
    ) {
    }

    /**
     * Shared shape for `ActivityDetail::update()`, so the ingest pipeline and
     * both backfill/correction commands write the same weather_* columns from
     * one place instead of repeating the field list.
     *
     * @return array{weather_temp_c: int, weather_humidity_pct: int, weather_rain_detected: bool, weather_wind_speed_kmh: int|null, weather_wind_gust_kmh: int|null, weather_wind_direction_deg: int|null, weather_rain_is_forecast: bool}
     */
    public function toActivityDetailAttributes(): array
    {
        return [
            'weather_temp_c' => $this->tempC,
            'weather_humidity_pct' => $this->humidityPct,
            'weather_rain_detected' => $this->rainDetected,
            'weather_wind_speed_kmh' => $this->windSpeedKmh,
            'weather_wind_gust_kmh' => $this->windGustKmh,
            'weather_wind_direction_deg' => $this->windDirectionDeg,
            'weather_rain_is_forecast' => $this->rainIsForecast,
        ];
    }
}
