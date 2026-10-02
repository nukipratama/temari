<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;

class SummaryRecomputer
{
    public function __construct(
        private readonly ActivityPipeline $pipeline,
    ) {
    }

    /**
     * Refresh one activity's `stream_summary` / `trimp_edwards` from its
     * ALREADY-STORED streams using the user's CURRENT heart-rate zones, then
     * rebuild that week's snapshot forward. Makes ZERO Strava HTTP calls. No-op
     * when the activity is gone, or has no stored streams / no detail row.
     */
    public function recomputeFromStoredStreams(int $activityId): void
    {
        $activity = Activity::with(['detail', 'stream'])->find($activityId);
        if ($activity === null) {
            return;
        }

        $this->pipeline->recomputeSummary($activity);
    }
}
