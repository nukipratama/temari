<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('strava:sync
    {--user= : Sync only this user id; otherwise all connected users}
    {--since= : Only consider activities started after this date (e.g. 2026-05-01 or "-7 days"); bounds the backfill walk}')]
#[Description('Fetch new Strava activities and queue them for ingestion.')]
class SyncCommand extends Command
{
    public function handle(SyncOrchestrator $orchestrator, AppConfig $config): int
    {
        if (! $config->boolean(AppConfigKey::StravaEnabled)) {
            $this->warn('Strava is disabled (kill-switch); skipping sync.');

            return self::SUCCESS;
        }

        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->warn('No users with a Strava connection found.');

            return self::SUCCESS;
        }

        $since = $this->resolveSince();

        foreach ($users as $user) {
            try {
                $queued = $orchestrator->syncUser($user, $since);
                $this->line("user {$user->id}: {$queued} new activities queued");
            } catch (Throwable $e) {
                // One bad connection (transient API error, open breaker) must not
                // abort the scheduled run or the other users. The per-user signal
                // is already persisted in StravaSyncLog + Pulse by the orchestrator.
                $this->warn("user {$user->id}: sync failed — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function resolveSince(): ?CarbonImmutable
    {
        $since = $this->option('since');
        if (! is_string($since) || $since === '') {
            return null;
        }

        return CarbonImmutable::parse($since);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(): Collection
    {
        return User::query()
            ->whereHas('stravaConnection', fn ($query) => $query->whereNull('revoked_at'))
            ->with('stravaConnection')
            ->when($this->option('user'), fn ($query, $userId) => $query->whereKey($userId))
            ->get();
    }
}
