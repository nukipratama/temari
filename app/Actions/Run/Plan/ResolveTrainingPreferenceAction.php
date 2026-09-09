<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\TrainingPreference;

/**
 * The athlete's training preferences, read once per request.
 *
 * Bound `scoped()` in AppServiceProvider — the plan engine reaches this row
 * from four unrelated places within one Plan render, and the memo can only
 * collapse those into one read if they are handed the same instance.
 * {@see TrainingPreference::booted()} drops the memo on any write.
 */
class ResolveTrainingPreferenceAction
{
    /** @var array<int, TrainingPreference|null> */
    private array $memo = [];

    public function __invoke(int $userId): ?TrainingPreference
    {
        if (array_key_exists($userId, $this->memo)) {
            return $this->memo[$userId];
        }

        return $this->memo[$userId] = TrainingPreference::query()
            ->where('user_id', $userId)
            ->first();
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
    }
}
