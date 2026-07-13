<?php

declare(strict_types=1);

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenMeteoClient
{
    private const string FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const string ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    /** Forecast endpoint reliably covers ~7 days of past; beyond that, use the archive endpoint. */
    private const int FORECAST_PAST_DAYS = 7;

    private const float RAIN_THRESHOLD_MM = 0.1;

    private const int TIMEOUT_SECONDS = 5;

    private const int FORECAST_CACHE_TTL = 21_600;

    private const int ARCHIVE_CACHE_TTL = 2_592_000;

    public function fetchForActivity(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
    ): ?WeatherSnapshot {
        $useArchive = $startedAt->diffInDays(CarbonImmutable::now()) > self::FORECAST_PAST_DAYS;

        return $this->fetch($latitude, $longitude, $startedAt, $useArchive, useCache: true);
    }

    /**
     * Force an archive-endpoint re-fetch, bypassing any forecast-sourced cache
     * entry. The backfill command calls this to correct rows whose rain flag was
     * only a forecast (rainIsForecast = true) once the archive is available; the
     * fresh archive result overwrites the cache so later reads stay corrected.
     */
    public function fetchArchive(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
    ): ?WeatherSnapshot {
        return $this->fetch($latitude, $longitude, $startedAt, useArchive: true, useCache: false);
    }

    private function fetch(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        bool $useArchive,
        bool $useCache,
    ): ?WeatherSnapshot {
        $cacheKey = $this->cacheKey($latitude, $longitude, $startedAt);

        // Cache the primitive shape, never the WeatherSnapshot object: a cached
        // object can come back as __PHP_Incomplete_Class across a runtime/extension
        // swap and blow up the return type. A non-array hit (a legacy poisoned
        // object) is treated as a miss and refetched, so old keys self-heal.
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->hydrate($cached);
            }
        }

        $snapshot = $this->fetchUncached($latitude, $longitude, $startedAt, $useArchive);
        if ($snapshot !== null) {
            $ttl = $useArchive ? self::ARCHIVE_CACHE_TTL : self::FORECAST_CACHE_TTL;
            Cache::put($cacheKey, $this->dehydrate($snapshot), $ttl);
        }

        return $snapshot;
    }

    /**
     * @return array{t: int, h: int, r: bool, ws: int|null, wg: int|null, wd: int|null, rf: bool}
     */
    private function dehydrate(WeatherSnapshot $snapshot): array
    {
        return [
            't' => $snapshot->tempC,
            'h' => $snapshot->humidityPct,
            'r' => $snapshot->rainDetected,
            'ws' => $snapshot->windSpeedKmh,
            'wg' => $snapshot->windGustKmh,
            'wd' => $snapshot->windDirectionDeg,
            'rf' => $snapshot->rainIsForecast,
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function hydrate(array $cached): ?WeatherSnapshot
    {
        if (! isset($cached['t'], $cached['h'], $cached['r'])) {
            return null;
        }

        return new WeatherSnapshot(
            tempC: (int) $cached['t'],
            humidityPct: (int) $cached['h'],
            rainDetected: (bool) $cached['r'],
            windSpeedKmh: isset($cached['ws']) ? (int) $cached['ws'] : null,
            windGustKmh: isset($cached['wg']) ? (int) $cached['wg'] : null,
            windDirectionDeg: isset($cached['wd']) ? (int) $cached['wd'] : null,
            rainIsForecast: (bool) ($cached['rf'] ?? false),
        );
    }

    private function fetchUncached(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        bool $useArchive,
    ): ?WeatherSnapshot {
        try {
            $response = $this->request()->get(
                $useArchive ? self::ARCHIVE_URL : self::FORECAST_URL,
                $this->params($latitude, $longitude, $startedAt, $useArchive),
            );
        } catch (Throwable $e) {
            Log::warning('open-meteo request failed', [
                'lat' => $latitude,
                'lng' => $longitude,
                'started_at' => $startedAt->toIso8601String(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('open-meteo request failed', [
                'status' => $response->status(),
                'lat' => $latitude,
                'lng' => $longitude,
                'hour' => $startedAt->format('Y-m-d\TH:00'),
            ]);

            return null;
        }

        return $this->parse($response->json(), $startedAt, $useArchive);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        bool $useArchive,
    ): array {
        $base = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hourly' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
            'timezone' => 'auto',
        ];

        if ($useArchive) {
            $base['start_date'] = $startedAt->toDateString();
            $base['end_date'] = $startedAt->toDateString();
        } else {
            $base['past_days'] = self::FORECAST_PAST_DAYS;
            $base['forecast_days'] = 1;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function parse(?array $payload, CarbonImmutable $startedAt, bool $useArchive): ?WeatherSnapshot
    {
        if (! is_array($payload) || ! isset($payload['hourly'])) {
            return null;
        }

        /** @var array<string, mixed> $hourly */
        $hourly = $payload['hourly'];

        /** @var list<string> $times */
        $times = $hourly['time'] ?? [];
        /** @var list<float|int|null> $temps */
        $temps = $hourly['temperature_2m'] ?? [];
        /** @var list<float|int|null> $humidities */
        $humidities = $hourly['relative_humidity_2m'] ?? [];
        /** @var list<float|int|null> $precipitations */
        $precipitations = $hourly['precipitation'] ?? [];
        /** @var list<float|int|null> $windSpeeds */
        $windSpeeds = $hourly['wind_speed_10m'] ?? [];
        /** @var list<float|int|null> $windGusts */
        $windGusts = $hourly['wind_gusts_10m'] ?? [];
        /** @var list<float|int|null> $windDirections */
        $windDirections = $hourly['wind_direction_10m'] ?? [];

        // Open-Meteo buckets hourly by local wall-clock (timezone=auto), matching Strava's start_date_local.
        $needle = $startedAt->format('Y-m-d\TH:00');
        $index = array_search($needle, $times, strict: true);
        if ($index === false) {
            return null;
        }

        $temp = $temps[$index] ?? null;
        $humidity = $humidities[$index] ?? null;
        $precipitation = $precipitations[$index] ?? 0;
        if ($temp === null || $humidity === null) {
            return null;
        }

        return new WeatherSnapshot(
            tempC: (int) round((float) $temp),
            humidityPct: (int) round((float) $humidity),
            rainDetected: ((float) $precipitation) > self::RAIN_THRESHOLD_MM,
            // Open-Meteo returns km/h by default; store as-is (the label renders "km/j").
            windSpeedKmh: $this->roundedOrNull($windSpeeds[$index] ?? null),
            windGustKmh: $this->roundedOrNull($windGusts[$index] ?? null),
            windDirectionDeg: $this->roundedOrNull($windDirections[$index] ?? null),
            // The forecast endpoint gives an uncertain rain flag; the archive one is observed.
            rainIsForecast: ! $useArchive,
        );
    }

    private function roundedOrNull(float|int|null $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }

    private function cacheKey(float $latitude, float $longitude, CarbonImmutable $startedAt): string
    {
        // v2: cache shape gained wind + rain-source fields; the prefix retires
        // stale v1 entries (temp/humidity/rain only) so they aren't served wind-less.
        return sprintf(
            'weather:v2:%s:%s:%s',
            number_format($latitude, 3, '.', ''),
            number_format($longitude, 3, '.', ''),
            $startedAt->format('Y-m-d\TH:00'),
        );
    }

    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->acceptJson();
    }
}
