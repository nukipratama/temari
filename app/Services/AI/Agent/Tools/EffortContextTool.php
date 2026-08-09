<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\SessionIntent;

final class EffortContextTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly RelativeEffort $relativeEffort,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_effort_context';
    }

    public function description(): string
    {
        return 'How hard this session was relative to its intent and to the last 28 days\' habits: '
            .'session_intent (workout/race/easy/unknown, tagged or inferred), relative_effort band, '
            ."and decoupling. If decoupling is missing, this run was too short to measure it, so don't "
            .'make it up.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'session_intent' => SessionIntent::forDetail($this->detail),
            'relative_effort' => $this->relativeEffort->forRun($this->activity, $this->detail),
            'decoupling_pct' => $this->summary()->decouplingPct(),
        ];
    }
}
