<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncActivitiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(SyncOrchestrator $orchestrator): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $orchestrator->syncUser($user);
    }
}
