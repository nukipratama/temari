<?php

declare(strict_types=1);

namespace App\Jobs\Story;

use App\Models\Activity;
use App\Services\Run\Story\Temari;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Standalone re-generation of a `story_lines` row for an activity (the
 * post-run mascot speech). Use after editing Temari's templates or mood
 * logic without re-ingesting from Strava.
 *
 * Daily greetings have their own caller — they fire on first dashboard
 * open per day, not via this job.
 */
class GenerateStoryLineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $activityId)
    {
    }

    public function handle(Temari $temari): void
    {
        /** @var Activity|null $activity */
        $activity = Activity::query()->with('detail')->find($this->activityId);
        if ($activity === null || $activity->detail === null) {
            return;
        }

        $temari->postRunLine($activity, $activity->detail);
    }
}
