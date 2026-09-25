<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use Throwable;
use App\Enums\StravaSyncSource;
use App\Models\Analytics\StravaSyncLog;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

class SyncActivitiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @param  int  $userId  Local user id whose connection drives the sync.
     * @param  int|null  $stravaActivityId  When set, ingest only this Strava
     *                                       activity (webhook push); otherwise
     *                                       run the full newest-first backfill.
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $stravaActivityId = null,
    ) {
    }

    public function handle(SyncOrchestrator $orchestrator): void
    {
        $user = User::query()->with('stravaConnection')->find($this->userId);
        if ($user === null) {
            return;
        }

        $connection = $user->stravaConnection;
        $credentialVersion = $connection?->credential_version;

        try {
            if ($this->stravaActivityId !== null) {
                $orchestrator->syncSingleActivity($user, $this->stravaActivityId);

                return;
            }

            $orchestrator->syncUser($user, source: StravaSyncSource::Manual);
        } catch (StravaRateLimitedException $e) {
            Log::warning('strava-sync rate-limited', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            $this->release(60);
        } catch (StravaCircuitOpenException) {
            // Strava is down and the breaker is open. Don't burn $tries hammering
            // it — drop this run; the hourly scheduled sync recovers once the
            // breaker half-opens.
            Log::info('strava-sync skipped — circuit breaker open', [
                'user_id' => $user->id,
            ]);
        } catch (StravaConnectionRevokedException $e) {
            // The API rejected the access token with a 401 (athlete deauthorized).
            // Same outcome as a failed refresh: revoke so we stop retrying.
            if ($connection === null) {
                return;
            }

            if (! $connection->markRevoked(expectedCredentialVersion: $credentialVersion)) {
                Log::info('strava-sync ignored a stale API 401 after credentials changed', [
                    'user_id' => $user->id,
                ]);

                return;
            }

            Pulse::record('strava_revoked', 'api_401')->count();

            Log::warning('strava-sync revoked connection after API 401', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        } catch (StravaTokenRefreshTransientException $e) {
            // A transient refresh failure (401 / 429 / 5xx / timeout) is NOT a
            // deauthorization: revoking here would destroy a healthy connection
            // and purge its un-ingested stubs over a momentary Strava blip.
            // Release with backoff so a later attempt recovers the sync.
            Log::warning('strava-sync released after transient token refresh failure', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            $this->release(60);
        } catch (StravaTokenRefreshFailedException $e) {
            // A rejected refresh means the athlete revoked us (or rotated the
            // refresh token out from under us). Mark the connection revoked so
            // sync stops instead of burning $tries on a token that will never
            // succeed.
            if ($connection === null) {
                return;
            }

            if (! $connection->markRevoked(expectedCredentialVersion: $credentialVersion)) {
                Log::info('strava-sync ignored a stale refresh failure after credentials changed', [
                    'user_id' => $user->id,
                ]);

                return;
            }

            // Surface revocations as a trend on the /pulse Strava-health card.
            Pulse::record('strava_revoked', 'token_refresh_failed')->count();

            Log::warning('strava-sync revoked connection after token refresh failure', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Once $tries is exhausted on an error none of the typed catches above
     * handle, the job lands in failed_jobs. Log it like the ingest siblings do,
     * and close the sync log with a status distinct from the routine per-attempt
     * 'error' rows (rate limits, token hiccups) so the athlete-facing state can
     * tell a permanent failure apart from one still retrying.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('strava.sync.failed', [
            'user_id' => $this->userId,
            'strava_activity_id' => $this->stravaActivityId,
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);

        StravaSyncLog::log(
            $this->userId,
            'failed',
            error: $exception->getMessage(),
            source: $this->stravaActivityId !== null ? StravaSyncSource::Webhook : StravaSyncSource::Manual,
        );
    }
}
