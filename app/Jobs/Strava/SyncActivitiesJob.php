<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

        try {
            if ($this->stravaActivityId !== null) {
                $orchestrator->syncSingleActivity($user, $this->stravaActivityId);

                return;
            }

            $orchestrator->syncUser($user);
        } catch (StravaTokenRefreshFailedException $e) {
            // A rejected refresh means the athlete revoked us (or rotated the
            // refresh token out from under us). Mark the connection revoked so
            // sync stops instead of burning $tries on a token that will never
            // succeed.
            $connection = $user->stravaConnection;
            if ($connection !== null) {
                $connection->markRevoked();
            }

            Log::warning('strava-sync revoked connection after token refresh failure', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
