<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * The origin currently being dispatched or narrated, held for the length of one dispatch block or
 * one job.
 *
 * Origin is a property of the dispatcher, not of the narrator: the same `RunInsightNarrator`
 * answers an ingest cascade, a "Reread" and a self-heal. Each entry point declares itself with
 * {@see self::set()}, {@see AnalysisService} stamps the value onto the job it dispatches, and the
 * job restores it before generating.
 *
 * {@see \App\Http\Middleware\SetDefaultNarrationOrigin} sets {@see AnalysisOrigin::User} once for
 * every authenticated web request; queue jobs and console commands call {@see self::set()}
 * explicitly, and an explicit call wins because it runs after the middleware default.
 *
 * Bound `scoped`, so a long-lived worker cannot carry one job's attribution into the next. An entry
 * point that declares nothing reads {@see AnalysisOrigin::Unknown}.
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
