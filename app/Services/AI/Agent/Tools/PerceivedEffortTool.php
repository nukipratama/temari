<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\Run\Plan\PlannedSessionTypes;

final class PerceivedEffortTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_perceived_effort';
    }

    public function description(): string
    {
        return 'The effort score (1-10) the athlete gave this run, if they logged one, with the range '
            .'the day\'s planned session usually feels like (target.low to target.high) and where the '
            .'score sits against it (relation: below, within or above). target and relation are null '
            .'when no plan covered the day or the session has no range. score null means they logged none.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $score = $this->detail->perceived_effort;
        if ($score === null) {
            return ['score' => null];
        }

        $type = PlannedSessionTypes::forRun($this->activity->user_id, $this->detail->start_date_local);
        $target = $type?->perceivedEffortTarget();

        return [
            'score' => $score,
            'session_type' => $type?->value,
            'target' => $target,
            'relation' => match (true) {
                $target === null => null,
                $score < $target['low'] => 'below',
                $score > $target['high'] => 'above',
                default => 'within',
            },
        ];
    }
}
