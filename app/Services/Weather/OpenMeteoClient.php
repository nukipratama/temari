<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Exceptions\Weather\OpenMeteoUnavailableException;
use App\Support\Numeric;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
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

    private const int SAMPLE_STEP_SECONDS = 900;

    private const int TIMEOUT_SECONDS = 5;

    private const int CONNECTION_ATTEMPTS = 2;

    private const int RETRY_SLEEP_MILLISECONDS = 1_000;

    private const int FORECAST_CACHE_TTL = 21_600;

    private const int ARCHIVE_CACHE_TTL = 2_592_000;

    public function fetchForActivity(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        int $elapsedSeconds,
    ): ?WeatherSnapshot {
        $useArchive = $startedAt->diffInDays(CarbonImmutable::now()) > self::FORECAST_PAST_DAYS;

        return $this->fetch($latitude, $longitude, $startedAt, $elapsedSeconds, $useArchive, useCache: true);
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
        int $elapsedSeconds,
    ): ?WeatherSnapshot {
        return $this->fetch($latitude, $longitude, $startedAt, $elapsedSeconds, useArchive: true, useCache: false);
    }

    private function fetch(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        int $elapsedSeconds,
        bool $useArchive,
        bool $useCache,
    ): ?WeatherSnapshot {
        $cacheKey = $this->cacheKey($latitude, $longitude, $startedAt, $elapsedSeconds);

        // Cache the primitive shape, never the WeatherSnapshot object: a cached
        // object can come back as __PHP_Incomplete_Class across a runtime/extension
        // swap and blow up the return type. A non-array hit (a legacy poisoned
        // object) is treated as a miss and refetched, so old keys self-heal.
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return $this->hydrate($cached);
            }
        }

        $snapshot = $this->fetchUncached($latitude, $longitude, $startedAt, $elapsedSeconds, $useArchive);
        if ($snapshot instanceof WeatherSnapshot) {
            $ttl = $useArchive ? self::ARCHIVE_CACHE_TTL : self::FORECAST_CACHE_TTL;
            Cache::put($cacheKey, $this->dehydrate($snapshot), $ttl);
        }

        return $snapshot;
    }

    /**
     * @return array{t: int, tm: int|null, h: int, dp: int|null, at: int|null, r: bool, ws: int|null, wg: int|null, wd: int|null, rf: bool}
     */
    private function dehydrate(WeatherSnapshot $snapshot): array
    {
        return [
            't' => $snapshot->tempC,
            'tm' => $snapshot->tempMaxC,
            'h' => $snapshot->humidityPct,
            'dp' => $snapshot->dewPointC,
            'at' => $snapshot->apparentTempC,
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
            tempC: Numeric::int($cached['t']),
            humidityPct: Numeric::int($cached['h']),
            rainDetected: (bool) $cached['r'],
            windSpeedKmh: isset($cached['ws']) ? Numeric::int($cached['ws']) : null,
            windGustKmh: isset($cached['wg']) ? Numeric::int($cached['wg']) : null,
            windDirectionDeg: isset($cached['wd']) ? Numeric::int($cached['wd']) : null,
            rainIsForecast: (bool) ($cached['rf'] ?? false),
            tempMaxC: isset($cached['tm']) ? Numeric::int($cached['tm']) : null,
            dewPointC: isset($cached['dp']) ? Numeric::int($cached['dp']) : null,
            apparentTempC: isset($cached['at']) ? Numeric::int($cached['at']) : null,
        );
    }

    private function fetchUncached(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        int $elapsedSeconds,
        bool $useArchive,
    ): ?WeatherSnapshot {
        try {
            $response = $this->request()->get(
                $useArchive ? self::ARCHIVE_URL : self::FORECAST_URL,
                $this->params($latitude, $longitude, $startedAt, $elapsedSeconds, $useArchive),
            );
        } catch (ConnectionException $e) {
            throw new OpenMeteoUnavailableException((string) preg_replace('/\?\S*/', '', $e->getMessage()));
        } catch (Throwable $e) {
            Log::warning('open-meteo request failed', [
                'started_at' => $startedAt->toIso8601String(),
                'error' => preg_replace('/\?\S*/', '', $e->getMessage()),
            ]);

            return null;
        }

        if ($response->status() === 429) {
            throw new OpenMeteoUnavailableException('open-meteo rate limited (429)');
        }

        if ($response->failed()) {
            Log::warning('open-meteo request failed', [
                'status' => $response->status(),
                'hour' => $startedAt->format('Y-m-d\TH:00'),
            ]);

            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = $response->json();

        return $this->parse($payload, $startedAt, $elapsedSeconds, $useArchive);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(
        float $latitude,
        float $longitude,
        CarbonImmutable $startedAt,
        int $elapsedSeconds,
        bool $useArchive,
    ): array {
        $base = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hourly' => 'temperature_2m,relative_humidity_2m,dew_point_2m,apparent_temperature,precipitation,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
            'timezone' => 'auto',
        ];

        if ($useArchive) {
            $base['start_date'] = $startedAt->toDateString();
            $base['end_date'] = $startedAt->addSeconds($elapsedSeconds)->toDateString();
        } else {
            $base['past_days'] = self::FORECAST_PAST_DAYS;
            $base['forecast_days'] = 1;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function parse(?array $payload, CarbonImmutable $startedAt, int $elapsedSeconds, bool $useArchive): ?WeatherSnapshot
    {
        if (! is_array($payload) || ! isset($payload['hourly']) || ! is_array($payload['hourly'])) {
            return null;
        }

        $hourly = $payload['hourly'];
        $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
        // Open-Meteo buckets hourly by local wall-clock (timezone=auto), matching Strava's start_date_local.
        $hourIndex = array_flip(array_filter($times, is_string(...)));
        $endedAt = $startedAt->addSeconds(max(0, $elapsedSeconds));
        $instants = $this->sampleInstants($startedAt, $endedAt);
        $sampled = fn (string $variable): array => $this->sampled($hourly[$variable] ?? null, $hourIndex, $instants);

        $temps = $sampled('temperature_2m');
        $humidities = $sampled('relative_humidity_2m');
        if ($temps === [] || $humidities === []) {
            return null;
        }

        $windGusts = $sampled('wind_gusts_10m');
        $midpoint = $startedAt->addSeconds(intdiv(max(0, $elapsedSeconds), 2));

        return new WeatherSnapshot(
            tempC: $this->roundedMean($temps),
            humidityPct: $this->roundedMean($humidities),
            rainDetected: $this->rainedDuring($hourly['precipitation'] ?? null, $hourIndex, $startedAt, $endedAt),
            // Open-Meteo returns km/h by default; store as-is (the label renders "km/j").
            windSpeedKmh: $this->roundedMeanOrNull($sampled('wind_speed_10m')),
            windGustKmh: $windGusts === [] ? null : (int) round(max($windGusts)),
            windDirectionDeg: $this->roundedOrNull($this->directionNear($hourly['wind_direction_10m'] ?? null, $hourIndex, $midpoint)),
            // The forecast endpoint gives an uncertain rain flag; the archive one is observed.
            rainIsForecast: ! $useArchive,
            tempMaxC: (int) round(max($temps)),
            dewPointC: $this->roundedMeanOrNull($sampled('dew_point_2m')),
            apparentTempC: $this->roundedMeanOrNull($sampled('apparent_temperature')),
        );
    }

    /**
     * @return non-empty-list<CarbonImmutable>
     */
    private function sampleInstants(CarbonImmutable $startedAt, CarbonImmutable $endedAt): array
    {
        $instants = [];
        for ($at = $startedAt; $at->lt($endedAt); $at = $at->addSeconds(self::SAMPLE_STEP_SECONDS)) {
            $instants[] = $at;
        }

        $instants[] = $endedAt;

        return $instants;
    }

    /**
     * Hourly values are instantaneous, so each instant interpolates between the
     * hours around it, or takes the earlier hour when the later one is missing.
     *
     * @param  array<string, int>  $hourIndex
     * @param  list<CarbonImmutable>  $instants
     * @return list<float>
     */
    private function sampled(mixed $series, array $hourIndex, array $instants): array
    {
        $values = [];
        foreach ($instants as $at) {
            $hour = $at->startOfHour();
            $before = $this->hourValue($series, $hourIndex, $hour);
            if ($before === null) {
                continue;
            }

            $after = $this->hourValue($series, $hourIndex, $hour->addHour());
            $fraction = ($at->minute * 60 + $at->second) / 3600;
            $values[] = $after === null ? $before : $before + ($after - $before) * $fraction;
        }

        return $values;
    }

    /**
     * Each hourly precipitation total covers the hour ending at its timestamp.
     *
     * @param  array<string, int>  $hourIndex
     */
    private function rainedDuring(mixed $series, array $hourIndex, CarbonImmutable $startedAt, CarbonImmutable $endedAt): bool
    {
        $hour = $startedAt->startOfHour()->addHour();
        do {
            if (($this->hourValue($series, $hourIndex, $hour) ?? 0.0) > self::RAIN_THRESHOLD_MM) {
                return true;
            }

            $hour = $hour->addHour();
        } while ($hour->subHour()->lt($endedAt));

        return false;
    }

    /**
     * A bearing can't be averaged linearly, so take the hour nearest the run's midpoint.
     *
     * @param  array<string, int>  $hourIndex
     */
    private function directionNear(mixed $series, array $hourIndex, CarbonImmutable $midpoint): ?float
    {
        $nearest = $midpoint->minute >= 30 ? $midpoint->startOfHour()->addHour() : $midpoint->startOfHour();

        return $this->hourValue($series, $hourIndex, $nearest)
            ?? $this->hourValue($series, $hourIndex, $midpoint->startOfHour());
    }

    /**
     * @param  array<string, int>  $hourIndex
     */
    private function hourValue(mixed $series, array $hourIndex, CarbonImmutable $hour): ?float
    {
        $index = $hourIndex[$hour->format('Y-m-d\TH:00')] ?? null;
        if ($index === null || ! is_array($series)) {
            return null;
        }

        $value = $series[$index] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  non-empty-list<float>  $values
     */
    private function roundedMean(array $values): int
    {
        return (int) round(array_sum($values) / count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function roundedMeanOrNull(array $values): ?int
    {
        return $values === [] ? null : $this->roundedMean($values);
    }

    private function roundedOrNull(?float $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }

    private function cacheKey(float $latitude, float $longitude, CarbonImmutable $startedAt, int $elapsedSeconds): string
    {
        // Versioned so a future cache-shape change can bump this prefix rather
        // than risk serving an old, differently-shaped entry.
        return sprintf(
            'weather:v3:%s:%s:%s:%d',
            number_format($latitude, 3, '.', ''),
            number_format($longitude, 3, '.', ''),
            $startedAt->format('Y-m-d\TH:i'),
            intdiv(max(0, $elapsedSeconds), 60),
        );
    }

    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->retry(
                self::CONNECTION_ATTEMPTS,
                self::RETRY_SLEEP_MILLISECONDS,
                static fn (Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            )
            ->acceptJson();
    }
}
