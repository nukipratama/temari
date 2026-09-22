<?php

declare(strict_types=1);

namespace App\Services\Run\Trend;

use App\Models\Activity;

final readonly class TrendSnapshotRepairDispatch
{
    public function __construct(private TrendSnapshotRepairService $repair)
    {
    }

    public function forActivity(Activity $activity): void
    {
        $date = $activity->detail?->start_date_local;
        if ($date === null) {
            return;
        }

        $this->repair->markDirty($activity->user_id, $date);
    }
}
