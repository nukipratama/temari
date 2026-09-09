<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\PlanAdaptation;

/**
 * One week's plan adaptation, read once per request per (athlete, week).
 *
 * Bound `scoped()` in AppServiceProvider — PlanController's `adaptation` prop
 * and both of PlanNarrationRequester's week-voice paths ask for the same row
 * within one deferred Plan render. {@see PlanAdaptation::booted()} drops the
 * memo on any write.
 */
class ResolveWeekAdaptationAction
{
    /** @var array<string, PlanAdaptation|null> */
    private array $memo = [];

    public function __invoke(int $userId, string $weekStart): ?PlanAdaptation
    {
        $key = $userId.'|'.$weekStart;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = PlanAdaptation::query()
            ->where('user_id', $userId)
            ->where('week_start', $weekStart)
            ->first();
    }

    public function forget(int $userId): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $userId.'|')) {
                unset($this->memo[$key]);
            }
        }
    }
}
