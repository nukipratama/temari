<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Models\StravaConnection;
use Illuminate\Http\Client\RequestException;

class ZoneFetcher
{
    private const array KEYS = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'];

    public function __construct(private readonly StravaClient $client)
    {
    }

    /**
     * Fetch and parse the athlete's HR-zone boundaries from `/athlete/zones`.
     *
     * Gated on the `profile:read_all` scope (required by the endpoint) so we
     * never provoke a 403 for a connection that never granted it. A 403 that
     * does come back anyway (e.g. a stale `scopes` string) is a soft skip, not
     * a revocation — `StravaClient` only maps 401 to
     * {@see \App\Services\Strava\Exceptions\StravaConnectionRevokedException}.
     *
     * @return array<string, array{lo:int, hi:int}>|null
     */
    public function fetch(StravaConnection $connection): ?array
    {
        if (! str_contains($connection->scopes, 'profile:read_all')) {
            return null;
        }

        try {
            $response = $this->client->get($connection, '/athlete/zones');
        } catch (RequestException $e) {
            if ($e->response->status() === 403) {
                return null;
            }

            throw $e;
        }

        /** @var list<array{min:int, max:int}> $ranges */
        $ranges = $response->json('heart_rate.zones') ?? [];

        return $this->parse($ranges);
    }

    /**
     * @param  list<array{min:int, max:int}>  $ranges  Strava's five {min,max} HR
     *                                                  zone ranges, ordered Z1..Z5.
     * @return array<string, array{lo:int, hi:int}>|null
     */
    private function parse(array $ranges): ?array
    {
        if (count($ranges) !== count(self::KEYS)) {
            return null;
        }

        $zones = [];
        foreach (self::KEYS as $index => $key) {
            $min = (int) $ranges[$index]['min'];
            $max = (int) $ranges[$index]['max'];

            // Strava's top zone has an open-ended max (-1); mirror the app's
            // gapless-band convention (config/runner.php) with a high cap.
            $zones[$key] = [
                'lo' => $min,
                'hi' => $max === -1 ? 999 : $max,
            ];
        }

        return $zones;
    }
}
