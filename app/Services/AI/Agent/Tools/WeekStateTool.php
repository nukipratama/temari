<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;

/**
 * The dashboard briefing's whole picture of the week in one read.
 *
 * These fields are produced together by {@see BriefingContext::forUser()} and
 * cost the same query work whether one or all fifteen are wanted, so splitting
 * them across several tools would only buy extra round trips.
 */
final class WeekStateTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly TrainingLoad $trainingLoad,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_week_state';
    }

    public function description(): string
    {
        return "This week's state: runs and km this week vs last week, volume_ramp_pct, how many "
            .'weeks in a row they\'ve been active, fitness direction, what time of day it is '
            .'(time_bucket), whether they\'ve already run today, how many hours since their last '
            .'run, form_status, plus readiness_ceiling and build_nudge which cap how hard you\'re '
            .'allowed to suggest. Call this before suggesting anything.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $load = $this->trainingLoad->summary($this->user, $this->asOf) ?? [];

        return BriefingContext::forUser($this->user, $this->asOf, $load)->toArray();
    }
}
