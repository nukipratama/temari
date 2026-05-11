<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestActivityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $activityId)
    {
    }

    public function handle(ActivityPipeline $pipeline): void
    {
        $activity = Activity::query()
            ->with('user.stravaConnection')
            ->find($this->activityId);
        if ($activity === null) {
            return;
        }

        $pipeline->ingest($activity);
    }
}
