<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\StravaConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The two reads every "is this athlete's history in yet?" question needs: when
 * they connected Strava, and which of their runs the pipeline still owes a
 * hydration. {@see RecapHydrationReadiness} asks per week, {@see HistoryNarrationGate}
 * asks per run, and both would otherwise restate the same join and the same
 * connection lookup.
 */
class HydrationBacklog
{
    public function connectedAt(int $userId): ?Carbon
    {
        $connectedAt = StravaConnection::query()->where('user_id', $userId)->value('created_at');

        return $connectedAt === null ? null : Carbon::parse($connectedAt);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, Carbon>
     */
    public function connectedAtFor(array $userIds): array
    {
        return StravaConnection::query()
            ->whereIn('user_id', $userIds)
            ->pluck('created_at', 'user_id')
            ->map(fn (mixed $at): Carbon => Carbon::parse($at))
            ->all();
    }

    /**
     * Runs still awaiting hydration, joined to the dates that place them.
     *
     * @param  list<int>  $userIds
     * @return Builder<Activity>
     */
    public function awaitingHydration(array $userIds): Builder
    {
        return Activity::query()
            ->awaitingHydration()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->whereIn('activities.user_id', $userIds);
    }

    /**
     * Whether a run of this user dated before $before still awaits hydration,
     * optionally only those dated on or after $since.
     */
    public function awaitsHydrationBefore(int $userId, ?Carbon $before, ?Carbon $since = null): bool
    {
        return $before !== null && $this->awaitingHydration([$userId])
            ->where('activity_details.start_date_local', '<', $before)
            ->when($since !== null, fn (Builder $query) => $query->where('activity_details.start_date_local', '>=', $since))
            ->exists();
    }
}
