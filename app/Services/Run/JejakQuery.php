<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Http\Requests\JejakFilterRequest;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class JejakQuery
{
    public function filtersFor(User $user, JejakFilterRequest $request): JejakFilters
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

        return new JejakFilters(
            range: $effectiveRange,
            rangeAutoWidened: $rangeAutoWidened,
            rangeStart: $rangeStart,
            moods: $request->moods(),
            distanceBand: $request->distanceBand(),
            search: $request->search(),
            sort: $request->sort(),
            week: $week,
        );
    }

    /**
     * @return Builder<Activity>
     */
    public function for(User $user, JejakFilters $filters): Builder
    {
        $query = Activity::query()
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

                if ($filters->distanceBand !== null) {
                    [$min, $max] = JejakFilters::DISTANCE_BANDS[$filters->distanceBand];
                    $q->where('distance', '>=', $min);
                    if ($max !== null) {
                        $q->where('distance', '<', $max);
                    }
                }

                if ($filters->search !== null) {
                    // Leading wildcard, so this can't use an index. Fine at a few
                    // hundred runs per user; revisit with a FULLTEXT index if a
                    // user's history ever makes it measurable.
                    $q->where('name', 'like', '%'.addcslashes($filters->search, '%_\\').'%');
                }
            })
            ->with(['detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards', 'workout_type'])]);

        // Mood lives on the post-run StoryLine, which is also what the list
        // renders, so filtering there keeps the filter and the displayed mood in
        // agreement. A run whose story line hasn't been written yet carries no
        // mood and is therefore not a match for any mood.
        if ($filters->moods !== []) {
            $query->whereIn('id', StoryLine::query()
                ->select('activity_id')
                ->where('user_id', $user->id)
                ->where('kind', StoryLine::KIND_POST_RUN)
                ->whereIn('mood', $filters->moods));
        }

        $this->applySort($query, $filters->sort);

        return $query;
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
            || $requestedRange === JejakFilters::RANGE_ALL
            || $latestRunDaysAgo <= JejakFilters::RANGE_DAYS[$requestedRange] - 1;

        if ($alreadyReaches) {
            return $requestedRange;
        }

        foreach (JejakFilters::RANGE_DAYS as $range => $days) {
            if ($latestRunDaysAgo <= $days - 1) {
                return $range;
            }
        }

        return JejakFilters::RANGE_ALL;
    }

    /**
     * Lower bound for a range, or null for "all" (no lower bound, show every run).
     */
    private function rangeStartFor(string $range): ?Carbon
    {
        if ($range === JejakFilters::RANGE_ALL) {
            return null;
        }

        return Carbon::today()->subDays(JejakFilters::RANGE_DAYS[$range] - 1);
    }

    /**
     * Ordering for the runs list. `newest` uses the activity id, which tracks
     * insertion order and needs no join. The ranked modes order by a detail
     * column, so they join it under an alias (the filter above uses a separate
     * `whereHas` subquery, so the alias avoids colliding with it).
     *
     * @param  Builder<Activity>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === JejakFilters::SORT_NEWEST) {
            $query->orderByDesc('id');

            return;
        }

        $query->join('activity_details as sort_detail', 'sort_detail.activity_id', '=', 'activities.id')
            ->select('activities.*');

        if ($sort === JejakFilters::SORT_LONGEST) {
            $query->orderByDesc('sort_detail.distance');

            return;
        }

        // Fastest = lowest seconds per metre. Runs missing distance or time have
        // no pace to rank, so they drop out rather than sorting as infinitely
        // fast (and the division stays safe).
        $query->where('sort_detail.distance', '>', 0)
            ->where('sort_detail.moving_time', '>', 0)
            ->orderByRaw('sort_detail.moving_time / sort_detail.distance asc');
    }
}
