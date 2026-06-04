<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\User;
use App\Services\Strava\ActivityFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

class SyncOrchestrator
{
    private const int LOCK_TTL_SECONDS = 300;

    public function __construct(private readonly ActivityFetcher $fetcher)
    {
    }

    public function syncUser(User $user, ?CarbonImmutable $since = null): int
    {
        $connection = $user->stravaConnection;
        if ($connection === null || $connection->isRevoked()) {
            return 0;
        }

        $lock = Cache::lock("strava-sync:user-{$user->id}", self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            Log::info('strava-sync skipped — another run holds the lock', ['user_id' => $user->id]);

            return 0;
        }

        try {
            $newIds = $this->fetcher->fetchNewExternalIds($connection, $since);
            if ($newIds === []) {
                return 0;
            }

            $inserted = $this->insertActivityRows($user->id, $newIds);

            foreach (Activity::query()
                ->where('user_id', $user->id)
                ->whereIn('strava_external_id', $newIds)
                ->orderBy('id')
                ->lazy() as $activity
            ) {
                IngestActivityJob::dispatch($activity->id);
            }

            Log::info('strava-sync queued ingestion', [
                'user_id' => $user->id,
                'inserted' => $inserted,
            ]);

            // Sync-run outcome for the /pulse Strava-health card trend.
            Pulse::record('strava_sync', 'inserted', $inserted)->sum()->count();

            return $inserted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Ingest a single activity by its Strava external id (webhook push path).
     * Inserts the row if it is new, then dispatches exactly one IngestActivityJob.
     * Idempotent: an already-stored activity reuses its row and re-ingests.
     */
    public function syncSingleActivity(User $user, int $externalId): bool
    {
        $connection = $user->stravaConnection;
        if ($connection === null || $connection->isRevoked()) {
            return false;
        }

        $this->insertActivityRows($user->id, [$externalId]);

        $activity = Activity::query()
            ->where('user_id', $user->id)
            ->where('strava_external_id', $externalId)
            ->first();

        if ($activity === null) {
            return false;
        }

        IngestActivityJob::dispatch($activity->id);

        Log::info('strava-sync queued single activity from webhook', [
            'user_id' => $user->id,
            'strava_external_id' => $externalId,
        ]);

        return true;
    }

    /**
     * @param  list<int>  $externalIds  already sorted ascending (oldest first)
     */
    private function insertActivityRows(int $userId, array $externalIds): int
    {
        $now = now();
        $rows = array_map(fn (int $id): array => [
            'user_id' => $userId,
            'strava_external_id' => $id,
            'fetched_at' => $now,
            'analyzed_at' => null,
            'detail_fail_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $externalIds);

        return DB::transaction(
            fn (): int => (int) Activity::query()->insertOrIgnore($rows)
        );
    }
}
