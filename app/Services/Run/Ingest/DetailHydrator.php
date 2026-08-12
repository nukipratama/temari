<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;

/**
 * Queues the detail + streams fetch for a run we so far only know from its
 * `/athlete/activities` summary. Called from the surfaces that make the deeper
 * data worth its two Strava reads: opening a run, and picking one as a Past You
 * comparison.
 */
class DetailHydrator
{
    /**
     * Returns whether a fetch was queued. {@see IngestActivityJob} is
     * `ShouldBeUnique`, so repeated views collapse onto one queued fetch.
     */
    public function hydrate(int $activityId): bool
    {
        $hydratable = Activity::query()
            ->withStubs()
            ->summaryOnly()
            ->whereKey($activityId)
            ->whereHas('user', fn ($query) => $query->where('is_demo', false))
            ->whereHas('user.stravaConnection', fn ($query) => $query->whereNull('revoked_at'))
            ->exists();

        if (! $hydratable) {
            return false;
        }

        IngestActivityJob::dispatch($activityId);

        return true;
    }
}
