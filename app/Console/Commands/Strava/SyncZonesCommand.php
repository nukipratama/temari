<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Models\User;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\ZoneFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('strava:sync-zones
    {--user= : Sync only this user id; otherwise all connected users}')]
#[Description('Fetch HR-zone boundaries from Strava (/athlete/zones) and sync them into runner_profiles.')]
class SyncZonesCommand extends Command
{
    public function handle(ZoneFetcher $fetcher): int
    {
        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->warn('No eligible users with a Strava connection found.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            try {
                $this->syncOne($user, $fetcher);
            } catch (StravaConnectionRevokedException|StravaTokenRefreshFailedException $e) {
                // Same as SyncZonesJob: a 401 or a rejected refresh means the athlete
                // deauthorized us. Revoke so this stops being retried every month and
                // the UI stops showing a stale "connected" state.
                $user->stravaConnection?->markRevoked();

                $this->warn("user {$user->id}: connection revoked — {$e->getMessage()}");
            } catch (Throwable $e) {
                // One bad connection must not abort the scheduled run for the
                // other users — mirrors strava:sync.
                $this->warn("user {$user->id}: zone sync failed — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function syncOne(User $user, ZoneFetcher $fetcher): void
    {
        $connection = $user->stravaConnection;
        if ($connection === null) {
            return;
        }

        $zones = $fetcher->fetch($connection);
        if ($zones === null) {
            $this->line("user {$user->id}: no zones synced");

            return;
        }

        if ($zones === $user->hrProfile()['hr_zones']) {
            $this->line("user {$user->id}: zones unchanged, skipped");

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

        $this->line("user {$user->id}: zones synced from Strava");
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(): Collection
    {
        return User::query()
            ->notDemo()
            ->whereHas('stravaConnection', fn ($query) => $query
                ->whereNull('revoked_at')
                ->where('scopes', 'like', '%profile:read_all%'))
            ->whereDoesntHave('runnerProfile', fn ($query) => $query->where('source', 'manual'))
            ->with('stravaConnection')
            ->when($this->option('user'), fn ($query, $userId) => $query->whereKey($userId))
            ->get();
    }
}
