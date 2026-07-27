<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\AI\Context\ActivityNarrationContext;

final class WeatherTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Cuaca saat lari: suhu, kelembapan, hujan (dengan rain_source observed/forecast), angin. '
            .'Panggil sebelum menyalahkan kebugaran atas HR yang tinggi atau pace yang drop.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $shared = ActivityNarrationContext::fromDetail($this->detail);

        return [
            'weather_temp_c' => $shared->weatherTempC,
            'weather_humidity_pct' => $this->detail->weather_humidity_pct,
            'weather_rain' => $shared->weatherRain,
            'weather_rain_source' => $shared->weatherRainSource,
            'weather_wind_speed_kmh' => $shared->weatherWindSpeedKmh,
            'weather_wind_gust_kmh' => $shared->weatherWindGustKmh,
            'weather_wind_direction_deg' => $shared->weatherWindDirectionDeg,
        ];
    }
}
