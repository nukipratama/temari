<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The athletes a per-user AI kickoff is allowed to spend on today: someone who
 * opened the app inside the active window, demo excluded. The daily briefing,
 * the weekly profile voice, the trend read and the plan's narration half all
 * draw their user list from here, so the eligibility rule lives in one place
 * rather than being restated per command.
 *
 * @see docs/decisions/narration-follows-the-athlete-not-the-run.md
 */
class RecentlyActiveUsers
{
    public const int ACTIVE_WINDOW_DAYS = 7;

    /**
     * @return Collection<int, User>
     */
    public function __invoke(): Collection
    {
        return User::query()
            ->notDemo()
            ->where('last_seen_at', '>=', Carbon::today()->subDays(self::ACTIVE_WINDOW_DAYS))
            ->get();
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values($this->__invoke()->map(fn (User $user): int => $user->id)->all());
    }
}
