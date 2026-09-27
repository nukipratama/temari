<?php

declare(strict_types=1);

namespace App\Actions\Geo;

use App\Services\Geo\Exceptions\NominatimRateSlotUnavailableException;
use App\Services\Geo\ResolvedLocation;
use App\Services\Geo\ReverseGeocodeOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReverseGeocodeAction
{
    private const string URL = 'https://nominatim.openstreetmap.org/reverse';

    private const int TIMEOUT_SECONDS = 6;

    private const int CACHE_TTL = 2_592_000; // 30 days

    private const string RATE_SLOT_LOCK_KEY = 'geo:nominatim:request-slot-lock';

    private const string NEXT_RATE_SLOT_KEY = 'geo:nominatim:next-request-at';

    private const int RATE_SLOT_INTERVAL_MICROSECONDS = 1_000_000;

    private const int RATE_SLOT_LOCK_SECONDS = 20;

    private const int RATE_SLOT_CACHE_SECONDS = 10;

    private const int TRANSIENT_FAILURE_CACHE_TTL = 600;

    public function __invoke(float $lat, float $lng): ?ResolvedLocation
    {
        $cacheKey = $this->cacheKey($lat, $lng);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof ResolvedLocation) {
            return $cached;
        }
        if ($cached instanceof ReverseGeocodeOutcome) {
            return null;
        }

        $outcome = $this->fetchWithRateSlot($lat, $lng);
        $ttl = $outcome === ReverseGeocodeOutcome::TransientFailure
            ? self::TRANSIENT_FAILURE_CACHE_TTL
            : self::CACHE_TTL;
        Cache::put($cacheKey, $outcome, $ttl);

        return $outcome instanceof ResolvedLocation ? $outcome : null;
    }

    public function hasTransientFailure(float $lat, float $lng): bool
    {
        return Cache::get($this->cacheKey($lat, $lng)) === ReverseGeocodeOutcome::TransientFailure;
    }

    private function fetchWithRateSlot(float $lat, float $lng): ResolvedLocation|ReverseGeocodeOutcome
    {
        $lock = Cache::lock(self::RATE_SLOT_LOCK_KEY, self::RATE_SLOT_LOCK_SECONDS);
        if (! $lock->get()) {
            throw new NominatimRateSlotUnavailableException();
        }

        $requestStartedAt = null;
        try {
            $now = (int) Carbon::now()->format('Uu');
            $nextAllowedAt = (int) Cache::get(self::NEXT_RATE_SLOT_KEY, 0);
            if ($nextAllowedAt > $now) {
                throw new NominatimRateSlotUnavailableException();
            }

            Cache::put(
                self::NEXT_RATE_SLOT_KEY,
                $now + self::RATE_SLOT_INTERVAL_MICROSECONDS,
                self::RATE_SLOT_CACHE_SECONDS,
            );
            $requestStartedAt = $now;

            return $this->fetchUncached($lat, $lng);
        } finally {
            try {
                if ($requestStartedAt !== null) {
                    Cache::put(
                        self::NEXT_RATE_SLOT_KEY,
                        (int) Carbon::now()->format('Uu') + self::RATE_SLOT_INTERVAL_MICROSECONDS,
                        self::RATE_SLOT_CACHE_SECONDS,
                    );
                }
            } finally {
                $lock->release();
            }
        }
    }

    private function fetchUncached(float $lat, float $lng): ResolvedLocation|ReverseGeocodeOutcome
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent(),
                'Accept-Language' => 'en',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::URL, [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 14, // suburb level — gives kecamatan + kota
                    'addressdetails' => 1,
            ]);

            if (! $response->ok()) {
                return ReverseGeocodeOutcome::TransientFailure;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['address'] ?? null)) {
                return ReverseGeocodeOutcome::TransientFailure;
            }

            return $this->formatAddress($payload['address']) ?? ReverseGeocodeOutcome::NoAddress;
        } catch (Throwable $e) {
            Log::info('nominatim resolve failed', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);

            return ReverseGeocodeOutcome::TransientFailure;
        }
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function formatAddress(array $address): ?ResolvedLocation
    {
        // Nominatim uses inconsistent keys per country; try a few likely
        // ones in Indonesia → fall back to the global ones. Stops at the
        // first hit for each rank so the assembled string stays compact.
        $parts = array_filter([
            $this->firstFilled($address, ['suburb', 'village', 'town', 'hamlet']),
            $this->firstFilled($address, ['city_district', 'borough', 'county']),
            $this->firstFilled($address, ['city', 'municipality', 'state_district']),
            $this->firstFilled($address, ['state', 'region']),
            $this->firstFilled($address, ['country']),
        ], fn (?string $v): bool => $v !== null && $v !== '');

        if (count($parts) === 0) {
            return null;
        }

        $country = $address['country_code'] ?? null;

        return new ResolvedLocation(
            name: implode(', ', $parts),
            country: is_string($country) ? strtoupper($country) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  array<int, string>  $keys
     */
    private function firstFilled(array $address, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $address[$k] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    private function cacheKey(float $lat, float $lng): string
    {
        // ~110m grid — caches adjacent coords together so a small route
        // jitter doesn't blow the cache.
        return sprintf('geo:nominatim:v2:%.3f:%.3f', $lat, $lng);
    }

    private function userAgent(): string
    {
        $contact = config('app.url') ?? 'https://github.com/nukipratama/temari';

        return sprintf('Temari/1.0 (%s)', $contact);
    }
}
