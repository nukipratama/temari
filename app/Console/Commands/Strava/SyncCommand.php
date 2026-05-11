<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use Illuminate\Database\Eloquent\Collection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strava:sync {--user= : Sync only this user id; otherwise all connected users}')]
#[Description('Fetch new Strava activities and queue them for ingestion.')]
class SyncCommand extends Command
{
    public function handle(SyncOrchestrator $orchestrator): int
    {
        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->warn('No users with a Strava connection found.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $queued = $orchestrator->syncUser($user);
            $this->line(sprintf('user %d: %d new activities queued', $user->id, $queued));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(): Collection
    {
        $userIdOption = $this->option('user');

        $query = User::query()
            ->whereHas('stravaConnection')
            ->with('stravaConnection');

        if ($userIdOption !== null) {
            $query->whereKey($userIdOption);
        }

        return $query->get();
    }
}
