<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Http\Requests\FeedFilterRequest;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FeedQuery
{
    public function filtersFor(User $user, FeedFilterRequest $request): FeedFilters
    {
        $requestedRange = $request->range();

        // Age (in whole days) of the newest analyzed run; null when the user has
        // no dated analyzed runs at all. Drives the auto-widen below.
        $latestRunDaysAgo = $this->latestRunDaysAgo($user);

        // If the user has runs, always show them: widen to the smallest range
        // that reaches the newest run, escalating past every preset to "all" so
        // the page never asks the user to widen the window by hand.
        $effectiveRange = $this->widenRangeToReach($requestedRange, $latestRunDaysAgo);
        $rangeAutoWidened = $effectiveRange !== $requestedRange;
        $rangeStart = $this->rangeStartFor($effectiveRange);

        $week = $request->week();

        // A deep link to one week (the weekly-recap notification) has to reach
        // that week regardless of how far back it is, so it overrides both the
        // requested range and the auto-widen.
        if ($week !== null) {
            $rangeStart = $week->copy()->subDays(6);
            $rangeAutoWidened = false;
        }

        return new FeedFilters(
            range: $effectiveRange,
            rangeAutoWidened: $rangeAutoWidened,
            rangeStart: $rangeStart,
            week: $week,
        );
    }

    /**
     * The feed's page window: the Monday that the `$weeks`-th most recent
     * run-bearing week starts on, plus whether any run sits behind it.
     *
     * Paging by *week* rather than by run keeps the unit the same as the one
     * the screen renders — a "load older weeks" press must add whole week
     * sections, and a heavy week must not consume someone else's page.
     * `since` is null only when the window holds no runs at all.
     *
     * @return array{since: ?Carbon, hasOlder: bool}
     */
    public function weekWindow(User $user, FeedFilters $filters, int $weeks): array
    {
        // Upper bound for a single-week deep link: `<` the next day so the whole
        // Sunday counts whatever time the run started.
        $weekEnd = $filters->week?->copy()->addDay();

        $dates = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->when(
                $filters->rangeStart !== null,
                fn (Builder $q): Builder => $q->where('start_date_local', '>=', $filters->rangeStart),
            )
            ->when(
                $weekEnd !== null,
                fn (Builder $q): Builder => $q->where('start_date_local', '<', $weekEnd),
            )
            ->orderByDesc('start_date_local')
            ->pluck('start_date_local');

        $seen = [];
        $oldest = null;

        foreach ($dates as $date) {
            $monday = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->startOfDay();
            $key = $monday->toDateString();

            if (isset($seen[$key])) {
                continue;
            }

            if (count($seen) === $weeks) {
                return ['since' => $oldest, 'hasOlder' => true];
            }

            $seen[$key] = true;
            $oldest = $monday;
        }

        return ['since' => $oldest, 'hasOlder' => false];
    }

    /**
     * @param  Carbon|null  $since  Page floor from {@see weekWindow}, tightening
     *                              the filters' own range start.
     * @return Builder<Activity>
     */
    public function for(User $user, FeedFilters $filters, ?Carbon $since = null): Builder
    {
        $lowerBound = $since ?? $filters->rangeStart;

        return Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', function ($q) use ($filters, $lowerBound) {
                if ($lowerBound !== null) {
                    $q->where('start_date_local', '>=', $lowerBound);
                }

                // Upper bound for a single-week deep link (the lower bound comes
                // from $rangeStart above). `<` the next day so the whole Sunday
                // is included whatever time the run started.
                if ($filters->week !== null) {
                    $q->where('start_date_local', '<', $filters->week->copy()->addDay());
                }
            })
            ->with([
                'detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'elapsed_time', 'average_heartrate', 'trimp_edwards', 'workout_type', 'summary_polyline']),
                'runCard' => fn ($q) => $q->select(['id', 'activity_id', 'rarity', 'special_move', 'badges']),
            ])
            ->orderByDesc('id');
    }

    /**
     * Whole days between today and the newest dated analyzed run, or null when
     * the user has no such run. Negative ages (future-dated rows) clamp to 0.
     */
    private function latestRunDaysAgo(User $user): ?int
    {
        $latestDate = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->max('start_date_local');

        if ($latestDate === null) {
            return null;
        }

        return (int) max(0, Carbon::parse($latestDate)->startOfDay()->diffInDays(Carbon::today(), false));
    }

    /**
     * Smallest range whose window reaches the newest run, escalating to "all"
     * (no lower bound) when the run is older than every preset. Returns the
     * requested range untouched when the user has no runs or it already reaches.
     */
    private function widenRangeToReach(string $requestedRange, ?int $latestRunDaysAgo): string
    {
        $alreadyReaches = $latestRunDaysAgo === null
            || $requestedRange === FeedFilters::RANGE_ALL
            || $latestRunDaysAgo <= FeedFilters::RANGE_DAYS[$requestedRange] - 1;

        if ($alreadyReaches) {
            return $requestedRange;
        }

        foreach (FeedFilters::RANGE_DAYS as $range => $days) {
            if ($latestRunDaysAgo <= $days - 1) {
                return $range;
            }
        }

        return FeedFilters::RANGE_ALL;
    }

    /**
     * Lower bound for a range, or null for "all" (no lower bound, show every run).
     */
    private function rangeStartFor(string $range): ?Carbon
    {
        if ($range === FeedFilters::RANGE_ALL) {
            return null;
        }

        return Carbon::today()->subDays(FeedFilters::RANGE_DAYS[$range] - 1);
    }
}
