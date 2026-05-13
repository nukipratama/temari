<?php

declare(strict_types=1);

namespace App\Jobs\Story;

use App\Models\Activity;
use App\Services\Run\Story\RunCardFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRunCardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $activityId)
    {
    }

    public function handle(RunCardFactory $factory): void
    {
        /** @var Activity|null $activity */
        $activity = Activity::query()->with('detail')->find($this->activityId);
        if ($activity === null || $activity->detail === null) {
            return;
        }

        $factory->build($activity, $activity->detail);
    }
}
