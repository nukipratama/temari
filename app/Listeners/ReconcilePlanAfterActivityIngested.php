<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActivityIngested;
use App\Models\Activity;
use App\Services\Run\Plan\PlanReconciliationDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\DebounceFor;

#[DebounceFor(120)]
final readonly class ReconcilePlanAfterActivityIngested implements ShouldQueue
{
    public function __construct(private PlanReconciliationDispatch $reconciliation)
    {
    }

    public function debounceId(ActivityIngested $event): string
    {
        return (string) ($event->userId
            ?? Activity::query()->whereKey($event->activityId)->value('user_id')
            ?? $event->activityId);
    }

    public function handle(ActivityIngested $event): void
    {
        $activity = Activity::query()->with('detail')->find($event->activityId);
        if ($activity === null || $activity->detail === null) {
            return;
        }

        $this->reconciliation->forActivity($activity);
    }
}
