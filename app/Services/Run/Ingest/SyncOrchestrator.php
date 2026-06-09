<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use Throwable;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\Analytics\StravaSyncLog;
use App\Models\User;
use App\Services\Strava\ActivityFetcher;
use App\Services\Strava\StravaClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

class SyncOrchestrator
{
    private const int LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly ActivityFetcher $fetcher,
        private readonly StravaClient $client,
    ) {
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
                $this->logSync($user->id, 'success', 0);

                return 0;
            }

            $inserted = $this->insertActivityRows($user->id, $newIds);

            Log::info('strava-sync inserted activity stubs', [
                'user_id' => $user->id,
                'inserted' => $inserted,
            ]);

            Pulse::record('strava_sync', 'inserted', $inserted)->sum()->count();

            $this->logSync($user->id, 'success', $inserted);

            return $inserted;
        } catch (Throwable $e) {
            $this->logSync($user->id, 'error', 0, $e->getMessage());

            throw $e;
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

        try {
            $this->insertActivityRows($user->id, [$externalId]);

            $activity = Activity::query()
                ->withStubs()
                ->where('user_id', $user->id)
                ->where('strava_external_id', $externalId)
                ->first();

            if ($activity === null) {
                $this->logSync($user->id, 'success', 0);

                return false;
            }

            IngestActivityJob::dispatch($activity->id);

            Log::info('strava-sync queued single activity from webhook', [
                'user_id' => $user->id,
                'strava_external_id' => $externalId,
            ]);

            $this->logSync($user->id, 'success', 1);

            return true;
        } catch (Throwable $e) {
            $this->logSync($user->id, 'error', 0, $e->getMessage());

            throw $e;
        }
    }

    private function logSync(int $userId, string $status, int $activitiesSynced, ?string $error = null): void
    {
        // Rate-limit headroom is only meaningful after a successful API call.
        $remaining = $error === null ? $this->client->rateLimitRemaining($userId) : null;

        StravaSyncLog::log($userId, $status, $activitiesSynced, 0, $error, $remaining);
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
