<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use Illuminate\Support\Collection;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\PastYouMatcher;
use DateTimeInterface;
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
        assert($connectedAt === null || $connectedAt instanceof DateTimeInterface || is_string($connectedAt));

        return $connectedAt === null ? null : Carbon::parse($connectedAt);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, Carbon>
     */
    public function connectedAtFor(array $userIds): array
    {
        /** @var Collection<int, Carbon> $createdAt */
        $createdAt = StravaConnection::query()
            ->whereIn('user_id', $userIds)
            ->pluck('created_at', 'user_id');

        return $createdAt->map(fn (Carbon $at): Carbon => Carbon::parse($at))->all();
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
        return $before instanceof Carbon && $this->awaitingHydration([$userId])
            ->where('activity_details.start_date_local', '<', $before)
            ->when($since instanceof Carbon, fn (Builder $query) => $query->where('activity_details.start_date_local', '>=', $since))
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
        $graceHours = config()->integer('ai.recap_hydration_grace_hours', 48);

        return $connectedAt instanceof Carbon && Carbon::now()->lt($connectedAt->copy()->addHours($graceHours));
    }

    /**
     * Whether narrating a run automatically now would read a history still
     * filling in. Bounded by the grace window after the athlete connected: past
     * it the run narrates whatever has landed, so a stuck drain cannot hold
     * narration forever.
     */
    public function awaitsOlderHydration(int $userId, ?Carbon $startedAt): bool
    {
        if (!$startedAt instanceof Carbon || ! $this->withinHydrationGrace($userId)) {
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
     * Whether ANY of this athlete's runs, at any age, still await hydration: wider than
     * {@see self::awaitsOlderHydration()}'s past-you-bounded reach, for the profile voice that reads
     * the whole history. Bounded by the same connect-anchored grace window.
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
