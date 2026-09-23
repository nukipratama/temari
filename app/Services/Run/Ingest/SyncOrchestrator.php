<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use Throwable;
use App\Enums\IngestState;
use App\Enums\StravaSyncSource;
use App\Enums\StravaReadSource;
use App\Enums\StravaReadPriority;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\Analytics\StravaSyncLog;
use App\Models\User;
use App\Services\AI\MaintainerAlerter;
use App\Services\Strava\ActivityFetcher;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\StravaClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
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
        private readonly SummaryIngest $summaryIngest,
        private readonly WeeklyAggregator $weeklyAggregator,
        private readonly MaintainerAlerter $alerter,
        private readonly ?AppConfig $config = null,
    ) {
    }

    public function syncUser(User $user, ?CarbonImmutable $since = null, StravaSyncSource $source = StravaSyncSource::Poll): int
    {
        if (! $this->stravaEnabled()) {
            return 0;
        }

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
            ['summaries' => $summaries, 'api_calls' => $apiCalls] = $this->fetcher->fetchNewSummaries($connection, StravaReadSource::fromSyncSource($source), $since);
            if ($summaries === []) {
                $this->logSync($user->id, 'success', 0, $apiCalls, source: $source);

                return 0;
            }

            $inserted = $this->summaryIngest->store($user->id, $summaries);
            $this->rebuildAggregates($user, $summaries);

            Log::info('strava-sync stored activity summaries', [
                'user_id' => $user->id,
                'inserted' => $inserted,
                'api_calls' => $apiCalls,
            ]);

            Pulse::record('strava_sync', 'inserted', $inserted)->sum()->count();

            $this->logSync($user->id, 'success', $inserted, $apiCalls, source: $source);

            return $inserted;
        } catch (StravaConnectionRevokedException $e) {
            // The token was rejected with a 401. Trip the per-connection breaker:
            // revoke so sync stops picking this connection every hour instead of
            // crashing the scheduled command (parity with the SyncActivitiesJob
            // token-refresh-failure path).
            $connection->markRevoked();
            Pulse::record('strava_revoked', 'api_401')->count();
            $this->logSync($user->id, 'error', 0, 0, $e->getMessage(), source: $source);
            Log::warning('strava-sync revoked connection after API 401', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            return 0;
        } catch (Throwable $e) {
            $this->logSync($user->id, 'error', 0, 0, $e->getMessage(), source: $source);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Ingest a single activity by its Strava external id (webhook push path).
     * Inserts the row if it is new, then dispatches exactly one IngestActivityJob.
     *
     * Dedup: Strava can redeliver the same event, and the aspect type (create vs
     * update) is not propagated down to here, so we cannot tell a genuine update
     * apart from a duplicate create. An already-analyzed row means the detail and
     * streams are already fetched, so re-dispatching would re-spend two Strava API
     * calls and re-run the pipeline for nothing; we skip it. A stub (analyzed_at
     * null) still ingests. Genuine updates re-pull via the hourly poll or a manual
     * re-ingest, not this push path.
     */
    public function syncSingleActivity(User $user, int $externalId, StravaSyncSource $source = StravaSyncSource::Webhook): bool
    {
        if (! $this->stravaEnabled()) {
            return false;
        }

        $connection = $user->stravaConnection;
        if ($connection === null || $connection->isRevoked()) {
            return false;
        }

        try {
            $this->insertStub($user->id, $externalId);

            $activity = Activity::query()
                ->withStubs()
                ->where('user_id', $user->id)
                ->where('strava_external_id', $externalId)
                ->first();

            if ($activity === null) {
                $this->logSync($user->id, 'success', 0, source: $source);

                return false;
            }

            if ($activity->analyzed_at !== null) {
                Log::info('strava-sync skipped redundant re-ingest for already-analyzed activity', [
                    'user_id' => $user->id,
                    'strava_external_id' => $externalId,
                ]);

                $this->logSync($user->id, 'success', 0, source: $source);

                return false;
            }

            IngestActivityJob::dispatch($activity->id, StravaReadPriority::Live, StravaReadSource::fromSyncSource($source));

            Log::info('strava-sync queued single activity from webhook', [
                'user_id' => $user->id,
                'strava_external_id' => $externalId,
            ]);

            $this->logSync($user->id, 'success', 1, source: $source);

            return true;
        } catch (Throwable $e) {
            $this->logSync($user->id, 'error', 0, 0, $e->getMessage(), source: $source);

            throw $e;
        }
    }

    /**
     * Source of truth for the Strava kill-switch on the sync side — entry points
     * (command, job, webhook) can skip eagerly, but every sync path bottoms out
     * here so a missed guard still fails safe. Resolved lazily so the heavily
     * unit-tested constructor signature keeps its collaborators up front.
     */
    private function stravaEnabled(): bool
    {
        return ($this->config ?? app(AppConfig::class))->boolean(AppConfigKey::StravaEnabled);
    }

    /**
     * Roll the weekly snapshots forward from the oldest run this sync stored, so
     * a first-connect backfill lands its whole history in one pass instead of
     * once per ingested activity.
     *
     * @param  list<array<string, mixed>>  $summaries  oldest-first
     */
    private function rebuildAggregates(User $user, array $summaries): void
    {
        $start = $summaries[0]['start_date_local'] ?? $summaries[0]['start_date'] ?? null;
        if (! is_string($start) || $start === '') {
            return;
        }

        $this->weeklyAggregator->rebuildForwardFrom($user, CarbonImmutable::parse($start));
    }

    private function logSync(int $userId, string $status, int $activitiesSynced, int $apiCalls = 0, ?string $error = null, ?StravaSyncSource $source = null): void
    {
        $remaining = $this->client->rateLimitRemaining();

        // Rate-limit headroom is only meaningful on the log row after a successful
        // API call; the shared budget itself is worth reporting either way, and an
        // exhausted one is exactly what an errored sync tends to mean.
        StravaSyncLog::log($userId, $status, $activitiesSynced, $apiCalls, $error, $error === null ? $remaining : null, $source);

        $this->alerter->stravaBudgetLow($remaining['15min'], StravaClient::RATE_LIMIT_15MIN_MAX);
    }

    /**
     * The webhook carries only an activity id, so this path inserts a bare stub
     * and lets the ingest job fetch detail + streams.
     */
    private function insertStub(int $userId, int $externalId): void
    {
        $now = now();

        DB::transaction(fn (): int => (int) Activity::query()->insertOrIgnore([[
            'user_id' => $userId,
            'strava_external_id' => $externalId,
            'ingest_state' => IngestState::Summary->value,
            'fetched_at' => $now,
            'analyzed_at' => null,
            'detail_fail_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]]));
    }
}
