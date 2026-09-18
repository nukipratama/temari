<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\Run\Story\BriefingContext;

/**
 * The dashboard briefing's whole picture of the week in one read.
 *
 * These fields are produced together by {@see BriefingContext::forBriefingNarrator()}
 * and cost the same query work whether one or all fifteen are wanted, so
 * splitting them across several tools would only buy extra round trips.
 */
final class WeekStateTool extends UserTool
{
    public function name(): string
    {
        return 'get_week_state';
    }

    public function description(): string
    {
        return "This week's state: runs and km this week vs last week, volume_ramp (pct plus its own "
            .'relation: up/down/flat, no sign to read yourself), how many weeks in a row they\'ve been '
            .'active, fitness direction, what time of day it is (time_bucket), whether they\'ve already '
            .'run today, how many hours since their last run, form_status, plus readiness_ceiling and '
            .'build_nudge which cap how hard you\'re allowed to suggest. Call this before suggesting '
            .'anything. If history_loading is true, their history is still being imported: form_status, '
            .'fitness direction, volume_ramp and the ceiling all reflect that unknown rather than a '
            .'partial past.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return BriefingContext::forBriefingNarrator($this->user, $this->asOf)->toArray();
    }
}
