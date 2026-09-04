<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * The origin currently being dispatched or narrated, held for the length of one
 * dispatch block or one job.
 *
 * Origin is a property of the *dispatcher*, not of the narrator: the same
 * `RunInsightNarrator` answers an ingest cascade, a "Reread" and a self-heal.
 * Threading it through every narrator signature would push a dispatch concern
 * into every prompt builder, so each entry point declares itself once with
 * {@see self::set()} instead, {@see AnalysisService} stamps the value onto the
 * job it dispatches, and the job restores it before generating.
 *
 * Bound `scoped`, so it lasts exactly one HTTP request or one queue job and a
 * long-lived worker cannot carry one job's attribution into the next. Nothing is
 * inferred: an entry point that declares nothing reads
 * {@see AnalysisOrigin::Unknown} and shows up as unattributed.
 */
final class NarrationOrigin
{
    private AnalysisOrigin $current = AnalysisOrigin::Unknown;

    public function current(): AnalysisOrigin
    {
        return $this->current;
    }

    public function set(AnalysisOrigin $origin): void
    {
        $this->current = $origin;
    }
}
