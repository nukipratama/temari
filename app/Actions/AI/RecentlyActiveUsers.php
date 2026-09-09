<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The athletes a per-user AI kickoff is allowed to spend on today: a run inside
 * the active window, demo excluded. The daily briefing, the weekly profile voice
 * and the catch-up sweep all draw their user list from here, so the eligibility
 * rule lives in one place rather than being restated per command.
 */
class RecentlyActiveUsers
{
    /**
     * How recently a user must have run to be briefed, keyed off the run's own
     * date because an on-connect backfill stamps `analyzed_at` to now across a
     * whole imported history.
     */
    public const int ACTIVE_WINDOW_DAYS = 7;

    /**
     * @return Collection<int, User>
     */
    public function __invoke(): Collection
    {
        $activeUserIds = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activity_details.start_date_local', '>=', Carbon::today()->subDays(self::ACTIVE_WINDOW_DAYS))
            ->whereIn('activities.user_id', User::query()->notDemo()->select('id'))
            ->distinct()
            ->pluck('activities.user_id');

        return User::query()->whereIn('id', $activeUserIds)->get();
    }
}
