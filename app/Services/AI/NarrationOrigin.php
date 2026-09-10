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
 * into every prompt builder, so each entry point declares itself with
 * {@see self::set()} instead, {@see AnalysisService} stamps the value onto the
 * job it dispatches, and the job restores it before generating.
 *
 * An authenticated web request declares nothing itself: {@see
 * \App\Http\Middleware\SetDefaultNarrationOrigin}, appended to the `web`
 * middleware group, sets {@see AnalysisOrigin::User} once for every such
 * request. A queue job or console command still calls {@see self::set()}
 * explicitly, since neither runs through that middleware. Either way, a
 * dispatch site whose origin is not the default (a webhook, a devtools
 * re-arm) still declares itself explicitly, and that call wins because it
 * runs after the middleware default.
 *
 * Bound `scoped`, so it lasts exactly one HTTP request or one queue job and a
 * long-lived worker cannot carry one job's attribution into the next. Nothing is
 * inferred beyond the web default above: an entry point that declares nothing
 * and is not an authenticated web request reads {@see AnalysisOrigin::Unknown}
 * and shows up as unattributed.
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
