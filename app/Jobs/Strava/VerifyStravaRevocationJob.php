<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Enums\StravaReadSource;
use App\Enums\StravaSyncSource;
use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\StravaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Verifies a Strava deauthorization webhook out-of-band before acting on it.
 *
 * The webhook body is unauthenticated and forgeable: anyone who knows the
 * owner's public athlete id could POST a fake deauth and DoS the sync. So the
 * controller treats the event as a hint only and queues this job, which confirms
 * the grant is really gone by hitting `/athlete` with the stored token. A 401
 * (or a permanently-rejected refresh) proves the revocation is genuine; a 2xx
 * means the event was forged or stale and the connection is left untouched.
 */
#[Backoff([30, 120])]
#[Tries(3)]
class VerifyStravaRevocationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $connectionId,
        public readonly string $source,
        public readonly ?int $credentialVersion = null,
    ) {
    }

    public function handle(StravaClient $client): void
    {
        $connection = StravaConnection::query()->find($this->connectionId);
        if ($connection === null || $connection->isRevoked() || $this->credentialVersion === null || $connection->credential_version !== $this->credentialVersion) {
            return;
        }

        try {
            $client->get($connection, '/athlete', StravaReadSource::RevocationCheck);
        } catch (StravaConnectionRevokedException|StravaTokenRefreshFailedException) {
            // The stored credentials are genuinely rejected: the athlete really
            // did deauthorize us. Safe to revoke now.
            $this->revoke($connection);

            return;
        }

        // A 2xx means the grant is still live: the deauth event did not come from
        // a real revocation. Ignore it. Rate-limit / circuit-open / transport
        // errors bubble up so the job retries later.
        Log::warning('strava.webhook deauthorization ignored — grant still live', [
            'user_id' => $connection->user_id,
            'source' => $this->source,
        ]);
    }

    private function revoke(StravaConnection $connection): void
    {
        if (! $connection->markRevoked(expectedCredentialVersion: $this->credentialVersion, stravaRejected: true)) {
            return;
        }

        Pulse::record('strava_revoked', $this->source)->count();
        Log::info("strava.webhook {$this->source} — connection revoked (verified)", [
            'user_id' => $connection->user_id,
        ]);

        StravaSyncLog::log(
            $connection->user_id,
            'revoked',
            error: "Connection revoked via {$this->source}",
            source: StravaSyncSource::Webhook,
        );
    }
}
