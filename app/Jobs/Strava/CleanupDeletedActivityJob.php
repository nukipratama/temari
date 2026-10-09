<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Enums\StravaReadSource;
use App\Actions\AI\SettleEarlyNarrationAction;
use App\Actions\Run\DeleteIngestedRunAction;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\StravaClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

/**
 * Deletes a Strava-removed activity, healing its derived data through
 * {@see DeleteIngestedRunAction}. Run from the delete webhook so the webhook
 * itself still acks fast.
 */
#[Backoff([60, 300, 900, 3600])]
class CleanupDeletedActivityJob implements ShouldQueue
{
    use Queueable;

    private const int RETRY_WINDOW_HOURS = 24;

    public function __construct(
        public readonly int $userId,
        public readonly int $stravaActivityId,
    ) {
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    public function handle(
        StravaClient $client,
        SettleEarlyNarrationAction $settleEarlyNarration,
        DeleteIngestedRunAction $deleteIngestedRun,
    ): void {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $user = User::query()->with('stravaConnection')->find($this->userId);
        if ($user === null) {
            return;
        }

        $activity = Activity::query()
            ->withStubs()
            ->where('user_id', $user->id)
            ->where('strava_external_id', $this->stravaActivityId)
            ->with(['detail', 'runCard'])
            ->first();
        if ($activity === null) {
            return;
        }

        // The delete webhook body is unauthenticated and forgeable; confirm Strava
        // really returns a 404 for this activity before destroying the local row +
        // its narration. A still-resolvable activity (or an unverifiable
        // connection) is treated as an unverified hint and left untouched.
        if (! $this->confirmDeletedOnStrava($client, $user->stravaConnection)) {
            Log::info('strava.webhook delete event unverified — skipping local delete', [
                'user_id' => $user->id,
                'strava_external_id' => $this->stravaActivityId,
            ]);

            return;
        }

        ($deleteIngestedRun)($activity);

        // Deleting the last row in the athlete's backlog can leave it empty
        // same as a successful hydration would — see SettleEarlyNarrationAction.
        ($settleEarlyNarration)($user);

        Log::info('strava.webhook cleaned up deleted activity', [
            'user_id' => $user->id,
            'strava_external_id' => $this->stravaActivityId,
        ]);
    }

    /**
     * Confirm the activity is genuinely gone from Strava (a 404) using the stored
     * token, rather than trusting the forgeable webhook body. A 2xx (still
     * exists), a missing/revoked connection, a dead token or another 4xx return
     * false, since retrying cannot change them. Rate limits, an open circuit,
     * 5xx and transport failures propagate so the job retries within its window.
     */
    private function confirmDeletedOnStrava(StravaClient $client, ?StravaConnection $connection): bool
    {
        if ($connection === null || $connection->isRevoked()) {
            return false;
        }

        try {
            $client->get($connection, "/activities/{$this->stravaActivityId}", StravaReadSource::Cleanup);
        } catch (RequestException $e) {
            if ($e->response->serverError()) {
                throw $e;
            }

            return $e->response->status() === 404;
        } catch (StravaConnectionRevokedException|StravaTokenRefreshFailedException) {
            return false;
        }

        return false;
    }
}
