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
     * @return Builder<Activity>
     */
    public function for(User $user, FeedFilters $filters): Builder
    {
        return Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', function ($q) use ($filters) {
                if ($filters->rangeStart !== null) {
                    $q->where('start_date_local', '>=', $filters->rangeStart);
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
