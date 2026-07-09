<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Models\User;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use App\Services\Strava\ZoneFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncZonesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(ZoneFetcher $fetcher): void
    {
        $user = User::query()->with(['stravaConnection', 'runnerProfile'])->find($this->userId);
        if ($user === null) {
            return;
        }

        $connection = $user->stravaConnection;
        if ($connection === null || $connection->isRevoked()) {
            return;
        }

        // The manual editor is the source of truth once a user has set it; a
        // Strava sync must never overwrite that choice.
        $profile = $user->runnerProfile;
        if ($profile !== null && $profile->source === 'manual') {
            return;
        }

        try {
            $zones = $fetcher->fetch($connection);
        } catch (StravaRateLimitedException $e) {
            Log::warning('strava-zone-sync rate-limited', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            $this->release(60);

            return;
        } catch (StravaCircuitOpenException) {
            // Same "don't burn tries" reasoning as SyncActivitiesJob: drop this
            // run, the monthly schedule + next connect trigger recover it.
            Log::info('strava-zone-sync skipped — circuit breaker open', [
                'user_id' => $user->id,
            ]);

            return;
        } catch (StravaConnectionRevokedException $e) {
            $connection->markRevoked();

            Log::warning('strava-zone-sync revoked connection after API 401', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        } catch (StravaTokenRefreshTransientException $e) {
            Log::warning('strava-zone-sync released after transient token refresh failure', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            $this->release(60);

            return;
        } catch (StravaTokenRefreshFailedException $e) {
            $connection->markRevoked();

            Log::warning('strava-zone-sync revoked connection after token refresh failure', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        if ($zones === null) {
            return;
        }

        // No-op when Strava's zones match what's already effective — avoids
        // creating a spurious `runner_profiles` row (and a false "zona lama"
        // flag) when strava zones ≈ the config default.
        if ($zones === $user->hrProfile()['hr_zones']) {
            return;
        }

        $user->runnerProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'hr_zones' => $zones,
                'source' => 'strava',
                'strava_zones_synced_at' => Carbon::now(),
            ],
        );
    }
}
