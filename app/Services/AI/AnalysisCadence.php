<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * How often an analysis type is meant to be (re)generated, which governs how the
 * post-ingest cascade dispatches it: per-activity types fire on every ingest,
 * windowed types (daily/weekly/monthly) are debounced or deferred to a scheduled
 * command so a user with several runs in the window isn't re-billed each time,
 * and on-demand types are only ever triggered by an explicit user action.
 */
enum AnalysisCadence: string
{
    case PerActivity = 'per_activity';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case OnDemand = 'on_demand';
}
