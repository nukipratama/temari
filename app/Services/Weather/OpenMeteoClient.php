<?php

declare(strict_types=1);

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Open-Meteo weather lookup for run-start conditions.
 *
 * The plan spec lives at /Users/nukipratama/.claude/plans/alright-you-can-learn-temporal-moth.md
 * under "Weather integration".
 */
class OpenMeteoClient
{
    private const string FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const string ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    /**
     * Open-Meteo's forecast endpoint covers up to 7 days of recent past
     * reliably. Beyond that, hit the archive (reanalysis) endpoint.
     */
    private const int FORECAST_PAST_DAYS = 7;

    private const float RAIN_THRESHOLD_MM = 0.1;

    private const int TIMEOUT_SECONDS = 5;

    private const int FORECAST_CACHE_TTL = 21_600;     // 6 hours

    private const int ARCHIVE_CACHE_TTL = 2_592_000;   // 30 days

    public function fetchForActivity(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
    ): ?WeatherSnapshot {
        $useArchive = $startedAt->diffInDays(CarbonImmutable::now()) > self::FORECAST_PAST_DAYS;
        $cacheKey = $this->cacheKey($latitude, $longitude, $startedAt);
        $ttl = $useArchive ? self::ARCHIVE_CACHE_TTL : self::FORECAST_CACHE_TTL;

        return Cache::remember(
            $cacheKey,
            $ttl,
            fn (): ?WeatherSnapshot => $this->fetchUncached($latitude, $longitude, $startedAt, $useArchive),
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
            return null;
        }

        return $this->parse($response->json(), $startedAt);
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
            'hourly' => 'temperature_2m,relative_humidity_2m,precipitation',
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
    private function parse(?array $payload, CarbonImmutable $startedAt): ?WeatherSnapshot
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

        // Open-Meteo's hourly bucket label is the LOCAL-time hour (because we
        // passed timezone=auto). Strava's start_date_local is the same local
        // wall-clock string, so we just bucket-truncate to the hour and look
        // up the index.
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
        );
    }

    private function cacheKey(float $latitude, float $longitude, CarbonImmutable $startedAt): string
    {
        return sprintf(
            'weather:%s:%s:%s',
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
