<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverse-geocodes a (lat, lng) pair to an Indonesian-formatted display
 * string via OpenStreetMap Nominatim. Free, but bound by their TOS:
 *
 *   - Max 1 req/sec — enforced upstream by [[ResolveActivityLocationJob]]'s
 *     `WithoutOverlapping` lock + retry backoff, not here.
 *   - Custom User-Agent identifying the app (mandatory). Includes a
 *     contact URL from config so OSM can ping if usage misbehaves.
 *   - No bulk/aggressive use. Coords are rounded to ~110m precision
 *     before caching so adjacent points share a cache entry, and an
 *     activity is only resolved once.
 *
 * Returns null when the call errors out, the response is malformed, or
 * the address fields are too thin to assemble a useful display string.
 * Callers ([[ResolveActivityLocationJob]]) interpret null as "tried but
 * missed" and stamp `location_resolved_at` accordingly so we don't retry.
 */
class NominatimResolver
{
    private const string URL = 'https://nominatim.openstreetmap.org/reverse';

    private const int TIMEOUT_SECONDS = 6;

    private const int CACHE_TTL = 2_592_000; // 30 days

    public function reverse(float $lat, float $lng): ?ResolvedLocation
    {
        $cacheKey = $this->cacheKey($lat, $lng);

        // Sentinel-cache misses too: `Cache::remember` drops null-return
        // values, so without this every adjacent activity in a known-bad
        // ~110m grid would hit Nominatim again. We store `false` for
        // misses and translate it back to null on read.
        $cached = Cache::get($cacheKey, '__miss__');
        if ($cached === false) {
            return null;
        }
        if ($cached instanceof ResolvedLocation) {
            return $cached;
        }

        $resolved = $this->fetchUncached($lat, $lng);
        Cache::put($cacheKey, $resolved ?? false, self::CACHE_TTL);

        return $resolved;
    }

    private function fetchUncached(float $lat, float $lng): ?ResolvedLocation
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent(),
                'Accept-Language' => 'id,en',
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
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['address'] ?? null)) {
                return null;
            }

            return $this->formatAddress($payload['address']);
        } catch (Throwable $e) {
            Log::info('nominatim resolve failed', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);

            return null;
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
        return sprintf('geo:nominatim:%.3f:%.3f', $lat, $lng);
    }

    private function userAgent(): string
    {
        $contact = config('app.url') ?? 'https://github.com/anthropics/teman-lari';

        return sprintf('TemanLari/1.0 (%s)', $contact);
    }
}
