<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Enums\StravaReadPriority;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;

/**
 * Queues the detail + streams fetch for a run we so far only know from its
 * `/athlete/activities` summary. Called from the surfaces that make the deeper
 * data worth its two Strava reads: opening a run, and picking one as a Past You
 * comparison.
 *
 * These reads are user-driven and bursty (one per run opened, unlike a whole
 * history costing a handful), so they queue at
 * {@see StravaReadPriority::Background} and stop at the reserve floor rather
 * than starving a freshly-finished run's webhook ingest.
 */
class DetailHydrator
{
    /**
     * Returns whether a fetch was queued. {@see IngestActivityJob} is
     * `ShouldBeUnique`, so repeated views collapse onto one queued fetch.
     *
     * A run whose detail fetch has already exhausted
     * {@see Activity::MAX_DETAIL_FETCH_ATTEMPTS} is refused: a permanent 4xx
     * leaves `ingest_state` at `summary` deliberately, so without this guard a
     * deleted or unshared run would re-spend two reads on every view and on
     * every drain tick, forever.
     */
    public function hydrate(int $activityId): bool
    {
        $hydratable = Activity::query()
            ->withStubs()
            ->summaryOnly()
            ->whereKey($activityId)
            ->where('detail_fail_count', '<', Activity::MAX_DETAIL_FETCH_ATTEMPTS)
            ->whereHas('user', fn ($query) => $query->where('is_demo', false))
            ->whereHas('user.stravaConnection', fn ($query) => $query->whereNull('revoked_at'))
            ->exists();

        if (! $hydratable) {
            return false;
        }

        IngestActivityJob::dispatch($activityId, StravaReadPriority::Background);

        return true;
    }
}
