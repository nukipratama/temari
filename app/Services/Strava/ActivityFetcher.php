<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Models\Activity;
use App\Models\StravaConnection;
use Carbon\CarbonImmutable;

class ActivityFetcher
{
    private const int PER_PAGE = 200;

    public function __construct(private readonly StravaClient $client)
    {
    }

    /**
     * Fetch external ids of activities not yet stored locally.
     *
     * Contract: relies on the Strava `/athlete/activities` invariant that
     * results are ordered newest-first. The walk stops at the first already-known
     * activity (everything older is assumed synced) and returns the new ids
     * sorted oldest-first so the caller can ingest in chronological order.
     *
     * When `$since` is given, the walk also stops at the first activity that
     * started on or before it — bounding a first-connect backfill to a recent
     * window instead of pulling an athlete's entire history.
     *
     * @return list<int>
     */
    public function fetchNewExternalIds(StravaConnection $connection, ?CarbonImmutable $since = null): array
    {
        $existing = Activity::query()
            ->withStubs()
            ->where('user_id', $connection->user_id)
            ->pluck('strava_external_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
        $existingSet = array_flip($existing);

        $newIds = [];
        $page = 1;

        while (true) {
            $response = $this->client->get($connection, '/athlete/activities', [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            /** @var list<array<string, mixed>> $items */
            $items = $response->json() ?? [];
            if ($items === []) {
                break;
            }

            $stop = false;
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (isset($existingSet[$id])) {
                    $stop = true;

                    break;
                }
                if ($since !== null && $this->startedOnOrBefore($item, $since)) {
                    $stop = true;

                    break;
                }
                if (! $this->isRun($item)) {
                    continue;
                }
                $newIds[] = $id;
            }

            if ($stop || count($items) < self::PER_PAGE) {
                break;
            }
            $page++;
        }

        // Strava paginates newest-first; reverse so the caller inserts oldest-first.
        sort($newIds);

        return $newIds;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function startedOnOrBefore(array $item, CarbonImmutable $since): bool
    {
        $start = $item['start_date'] ?? null;
        if (! is_string($start) || $start === '') {
            return false;
        }

        return CarbonImmutable::parse($start)->lessThanOrEqualTo($since);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isRun(array $item): bool
    {
        return RunSportType::matches($item);
    }
}
