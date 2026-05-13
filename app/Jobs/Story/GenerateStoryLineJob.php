<?php

declare(strict_types=1);

namespace App\Jobs\Story;

use App\Models\Activity;
use App\Services\Run\Story\Temari;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
