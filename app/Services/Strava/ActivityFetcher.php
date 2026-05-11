<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Models\Activity;
use App\Models\StravaConnection;

class ActivityFetcher
{
    private const int PER_PAGE = 200;

    public function __construct(private readonly StravaClient $client)
    {
    }

    /**
     * Returns the external_ids of activities present on Strava but not yet
     * recorded for this user. Sorted ascending (oldest first) so the caller
     * can insert in chronological order — DB id ordering then matches the
     * order activities actually happened.
     *
     * Stops paginating as soon as we hit an already-recorded id, which keeps
     * routine sync cheap even when the athlete has thousands of historical runs.
     *
     * @return list<int>
     */
    public function fetchNewExternalIds(StravaConnection $connection): array
    {
        $existing = Activity::query()
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

            $hitKnown = false;
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (isset($existingSet[$id])) {
                    $hitKnown = true;

                    break;
                }
                if (! $this->isRun($item)) {
                    continue;
                }
                $newIds[] = $id;
            }

            if ($hitKnown || count($items) < self::PER_PAGE) {
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
    private function isRun(array $item): bool
    {
        $type = (string) ($item['sport_type'] ?? $item['type'] ?? '');

        return in_array($type, ['Run', 'VirtualRun', 'TrailRun'], strict: true);
    }
}
