<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The two reads every "is this athlete's history in yet?" question needs: when
 * they connected Strava, and which of their runs the pipeline still owes a
 * hydration. {@see \App\Services\AI\RecapHydrationReadiness} asks per week, {@see \App\Services\AI\HistoryNarrationGate}
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

    /**
     * Whether a run inside the trailing CTL window {@see TrainingLoad} scores readiness off still awaits hydration.
     */
    public function recentLoadAwaitsScoring(int $userId, Carbon $today): bool
    {
        return $this->awaitsHydrationBefore(
            $userId,
            $today->copy()->addDay()->startOfDay(),
            $today->copy()->subDays(TrainingLoad::CTL_TAU - 1)->startOfDay(),
        );
    }

    /**
     * Whether $userId is still inside the grace window every hydration hold is
     * bounded by, counted from Strava connect. False once connected long ago
     * (or never), so a stuck drain cannot hold anything forever.
     */
    public function withinHydrationGrace(int $userId): bool
    {
        $connectedAt = $this->connectedAt($userId);
        $graceHours = (int) config('ai.recap_hydration_grace_hours', 48);

        return $connectedAt !== null && Carbon::now()->lt($connectedAt->copy()->addHours($graceHours));
    }

    /**
     * Whether narrating a run automatically now would read a history still
     * filling in. Bounded by the grace window after the athlete connected: past
     * it the run narrates whatever has landed, so a stuck drain cannot hold
     * narration forever.
     */
    public function awaitsOlderHydration(int $userId, ?Carbon $startedAt): bool
    {
        if ($startedAt === null || ! $this->withinHydrationGrace($userId)) {
            return false;
        }

        return $this->olderHistoryHydrating($userId, $startedAt);
    }

    /**
     * A run inside past-you's reach before $startedAt still awaits hydration.
     */
    public function olderHistoryHydrating(int $userId, Carbon $startedAt): bool
    {
        return $this->awaitsHydrationBefore(
            $userId,
            $startedAt,
            $startedAt->copy()->subDays(PastYouMatcher::MAX_GAP_DAYS),
        );
    }

    /**
     * Whether ANY of this athlete's runs, at any age, still await hydration —
     * wider than {@see self::awaitsOlderHydration()}'s past-you-bounded reach.
     * The profile voice reads the athlete's whole history (lifetime stats,
     * full PR table, all-time plan adherence), so a run outside past-you's
     * 365-day window can still be exactly the one it would misread. Bounded
     * by the same connect-anchored grace window, so a stuck drain cannot hold
     * it forever and a long-connected athlete is never affected.
     */
    public function awaitsFullHydration(int $userId): bool
    {
        if (! $this->withinHydrationGrace($userId)) {
            return false;
        }

        return $this->awaitingHydration([$userId])->exists();
    }

    /**
     * Whether any run dated inside $month (Y-m) still awaits detail hydration,
     * grace-bounded like every other hold, so a long-connected athlete's one
     * never-hydrating summary run can't stick a month Pending forever.
     */
    public function monthAwaitsHydration(int $userId, string $month): bool
    {
        if (! $this->withinHydrationGrace($userId)) {
            return false;
        }

        $start = Carbon::parse($month.'-01')->startOfMonth();

        return $this->awaitsHydrationBefore($userId, $start->copy()->addMonthNoOverflow(), $start);
    }
}
