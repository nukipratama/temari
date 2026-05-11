<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use Illuminate\Support\Carbon;

/**
 * Detects + persists personal records for an activity.
 *
 *  - Distance PRs:  value_sec = elapsed_time at the target distance,
 *                   interpolated from per-km splits. This avoids counting
 *                   cool-down walks past the target — a 25 km run that
 *                   passed 21.0975 km at 2:47:52 gets the half PR cut
 *                   correctly even though total elapsed is much higher.
 *
 *  - Effort PRs:    value_sec = pace seconds per km for the best N-minute
 *                   window, parsed from the stream-summary best paces.
 */
class PersonalRecords
{
    /** Distance PR categories. value = elapsed seconds at distance. */
    private const array DISTANCE_CATEGORIES = [
        '1km' => 1_000,
        '5km' => 5_000,
        '10km' => 10_000,
        '15km' => 15_000,
        'half_marathon' => 21_097.5,
        'marathon' => 42_195.0,
    ];

    /** Effort PR categories — map of category → stream_summary key. */
    private const array EFFORT_CATEGORIES = [
        'best_5min' => 'best_5min_pace',
        'best_10min' => 'best_10min_pace',
        'best_20min' => 'best_20min_pace',
        'best_30min' => 'best_30min_pace',
        'best_60min' => 'best_60min_pace',
    ];

    /**
     * Check the activity against the user's existing PR ledger; insert / update
     * any rows whose value beats the current PR. Returns the list of categories
     * that were broken on this activity (empty if none).
     *
     * @param  ActivityDetail  $detail  the just-stored detail row (with stream_summary populated if available)
     * @return list<string>
     */
    public function detectAndStore(Activity $activity, ActivityDetail $detail): array
    {
        $broken = [];
        $setAt = $detail->start_date_local ?? Carbon::now();

        $broken = array_merge($broken, $this->checkDistancePrs($activity, $detail, $setAt));
        $broken = array_merge($broken, $this->checkEffortPrs($activity, $detail, $setAt));

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function checkDistancePrs(Activity $activity, ActivityDetail $detail, Carbon $setAt): array
    {
        $distance = (float) ($detail->distance ?? 0);
        $splits = is_array($detail->splits_metric) ? $detail->splits_metric : [];
        $broken = [];

        foreach (self::DISTANCE_CATEGORIES as $category => $targetMeters) {
            if ($distance < $targetMeters * 0.95) {
                continue;
            }
            $value = $this->timeAtDistance($splits, $targetMeters);
            if ($value === null || $value <= 0) {
                continue;
            }
            if ($this->updateIfFaster($activity, $category, $value, $setAt)) {
                $broken[] = $category;
            }
        }

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function checkEffortPrs(Activity $activity, ActivityDetail $detail, Carbon $setAt): array
    {
        $streamSummary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $broken = [];

        foreach (self::EFFORT_CATEGORIES as $category => $key) {
            $label = $streamSummary[$key] ?? null;
            if (! is_string($label)) {
                continue;
            }
            $value = PaceFormatter::parse($label);
            if ($value === null) {
                continue;
            }
            if ($this->updateIfFaster($activity, $category, $value, $setAt)) {
                $broken[] = $category;
            }
        }

        return $broken;
    }

    /**
     * Interpolates elapsed_time at exactly $targetMeters using per-km splits.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    public function timeAtDistance(array $splits, float $targetMeters): ?float
    {
        $accDist = 0.0;
        $accTime = 0.0;
        foreach ($splits as $split) {
            $distance = (float) ($split['distance'] ?? 0);
            $time = (float) ($split['elapsed_time'] ?? 0);
            if ($distance <= 0 || $time <= 0) {
                continue;
            }
            if ($accDist + $distance >= $targetMeters) {
                $remaining = $targetMeters - $accDist;

                return $accTime + $time * ($remaining / $distance);
            }
            $accDist += $distance;
            $accTime += $time;
        }

        return null;
    }

    /**
     * Returns true if the new value is faster (lower) than the existing PR
     * and the row was upserted; false if no PR change happened.
     */
    private function updateIfFaster(Activity $activity, string $category, float $value, Carbon $setAt): bool
    {
        $existing = PersonalRecord::query()
            ->where('user_id', $activity->user_id)
            ->where('category', $category)
            ->first();

        if ($existing !== null && $value >= $existing->value_sec) {
            return false;
        }

        PersonalRecord::query()->updateOrCreate(
            [
                'user_id' => $activity->user_id,
                'category' => $category,
            ],
            [
                'value_sec' => $value,
                'activity_id' => $activity->id,
                'set_at' => $setAt,
            ],
        );

        return true;
    }
}
