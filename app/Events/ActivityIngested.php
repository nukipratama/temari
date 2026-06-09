<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see \App\Services\Run\Ingest\ActivityPipeline} once an activity's
 * detail, streams, summary, and story layer are persisted. Carries only the
 * activity id (not the model) so a queued listener re-resolves fresh state
 * rather than working off a serialized snapshot. The post-run AI analysis
 * fan-out lives in {@see \App\Listeners\DispatchPostRunAnalysis}, keeping the
 * ingest pipeline ignorant of which analyses run.
 */
class ActivityIngested
{
    use Dispatchable;

    public function __construct(public int $activityId) {}
}
