<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strava:resync-activity {activity : Local activity id (not Strava external id)}')]
#[Description('Re-run the ingest pipeline for a single activity. Useful when iterating on compute logic.')]
class ResyncActivityCommand extends Command
{
    public function handle(ActivityPipeline $pipeline): int
    {
        /** @var Activity|null $activity */
        $activity = Activity::query()->find($this->argument('activity'));
        if ($activity === null) {
            $this->error("Activity {$this->argument('activity')} not found.");

            return self::FAILURE;
        }

        $pipeline->ingest($activity);
        $this->info("Activity {$activity->id} re-ingested.");

        return self::SUCCESS;
    }
}
