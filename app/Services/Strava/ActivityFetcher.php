<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Models\Activity;
use App\Models\StravaConnection;
use Carbon\CarbonImmutable;

class ActivityFetcher
{
    private const int PER_PAGE = 200;

    /**
     * Trailing window (days) within which the walk never stops at a known id, so
     * a backdated upload nested among already-synced runs is still discovered.
     */
    private const int DISCOVERY_WINDOW_DAYS = 14;

    public function __construct(private readonly StravaClient $client)
    {
    }

    /**
     * Fetch external ids of activities not yet stored locally.
     *
     * Contract: relies on the Strava `/athlete/activities` invariant that
     * results are ordered newest-first, and returns the new ids sorted
     * oldest-first so the caller can ingest in chronological order.
     *
     * The walk keeps scanning past a known id while the activity started within
     * the trailing DISCOVERY_WINDOW_DAYS window — a backdated upload sits at its
     * chronological position, nested among already-synced runs, so stopping at
     * the first known id would miss it. Below the window a known id means the
     * history is fully synced and the walk stops.
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
        $windowStart = CarbonImmutable::now()->subDays(self::DISCOVERY_WINDOW_DAYS);

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

            $stop = $this->collectNewIds($items, $existingSet, $windowStart, $since, $newIds);

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
     * Walk one page of activities, appending new run ids to $newIds. Returns true
     * when the caller should stop paginating (known id below the window, or the
     * $since bound reached).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, int>  $existingSet
     * @param  list<int>  $newIds
     */
    private function collectNewIds(array $items, array $existingSet, CarbonImmutable $windowStart, ?CarbonImmutable $since, array &$newIds): bool
    {
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (isset($existingSet[$id])) {
                // Don't stop inside the trailing window: a backdated upload can
                // still sit below this already-synced run.
                if ($this->startedAfter($item, $windowStart)) {
                    continue;
                }

                return true;
            }
            if ($since !== null && $this->startedOnOrBefore($item, $since)) {
                return true;
            }
            if (! $this->isRun($item)) {
                continue;
            }
            $newIds[] = $id;
        }

        return false;
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
    private function startedAfter(array $item, CarbonImmutable $moment): bool
    {
        $start = $item['start_date'] ?? null;
        if (! is_string($start) || $start === '') {
            return false;
        }

        return CarbonImmutable::parse($start)->greaterThan($moment);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isRun(array $item): bool
    {
        return RunSportType::matches($item);
    }
}
