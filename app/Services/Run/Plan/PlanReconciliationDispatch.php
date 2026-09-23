<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\Activity;

final readonly class PlanReconciliationDispatch
{
    public function __construct(private PlanReconciliationService $reconciliation)
    {
    }

    public function forActivity(Activity $activity): void
    {
        $date = $activity->detail?->start_date_local;
        if ($date === null) {
            return;
        }

        $this->reconciliation->markDirty($activity->user_id, $date);
    }
}
