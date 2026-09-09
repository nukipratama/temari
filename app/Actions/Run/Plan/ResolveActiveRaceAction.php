<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\RaceGoal;

/**
 * The athlete's one active race goal, read once per request.
 *
 * Bound `scoped()` in AppServiceProvider — Home, Plan and Profile each reach
 * the same goal through several unrelated collaborators, and the memo can only
 * collapse those into one read if they are handed the same instance.
 *
 * A caller that retires or supersedes a race must call {@see self::forget()}:
 * the mass `update()` those paths use fires no model events, which is why the
 * sibling {@see \App\Support\SharedPropCacheKey::ActiveRace} bust is explicit too.
 */
class ResolveActiveRaceAction
{
    /** @var array<int, RaceGoal|null> */
    private array $memo = [];

    public function __invoke(int $userId): ?RaceGoal
    {
        if (array_key_exists($userId, $this->memo)) {
            return $this->memo[$userId];
        }

        return $this->memo[$userId] = RaceGoal::query()
            ->where('user_id', $userId)
            ->active()
            ->first();
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
    }
}
