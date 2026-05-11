<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use Illuminate\Database\Eloquent\Collection;
use App\Models\Activity;
use App\Models\ActivityDetail;

/**
 * "Kamu vs Kamu Dulu" comparison — finds the user's nearest historical
 * comparable run for a given activity.
 *
 * Match criteria, all required:
 *   - Same pace-band bucket (recovery / easy / threshold), derived from
 *     average pace. Until the planner ships and we have workout_type,
 *     pace-band is the cheapest stable bucket.
 *   - Distance within ±20%.
 *   - Weather temperature within ±3°C *when both runs have weather*.
 *     Missing weather on either side passes (we'd rather match without
 *     the temp filter than miss the comparison).
 *   - At least 21 days of separation. "Vs you yesterday" isn't motivating.
 *
 * Of qualifying candidates, prefer the OLDEST — the comparison "vs you
 * three months ago" is more motivating than "vs you last month".
 */
class PastYouMatcher
{
    public const string BAND_RECOVERY = 'recovery';

    public const string BAND_EASY = 'easy';

    public const string BAND_THRESHOLD = 'threshold';

    private const float DISTANCE_TOLERANCE = 0.20;

    private const int TEMP_TOLERANCE_C = 3;

    private const int MIN_GAP_DAYS = 21;

    /** Pace-band edges in sec/km. */
    private const int RECOVERY_PACE_FLOOR_SEC = 450; // > 7:30/km

    private const int EASY_PACE_FLOOR_SEC = 390;     // > 6:30/km

    /**
     * @return array{
     *   past: ActivityDetail,
     *   pace_diff_sec: float,
     *   hr_diff_bpm: float|null,
     *   days_ago: int,
     * }|null
     */
    public function findMatch(Activity $activity, ActivityDetail $detail): ?array
    {
        $currentPaceSec = $this->paceSecPerKm($detail);
        $currentDistance = (float) ($detail->distance ?? 0);
        $startDate = $detail->start_date_local;

        if ($currentPaceSec === null || $currentDistance <= 0 || $startDate === null) {
            return null;
        }

        $band = $this->paceBand($currentPaceSec);
        $minDate = $startDate->copy()->subDays(self::MIN_GAP_DAYS)->endOfDay();
        $distanceLo = $currentDistance * (1 - self::DISTANCE_TOLERANCE);
        $distanceHi = $currentDistance * (1 + self::DISTANCE_TOLERANCE);

        /** @var Collection<int, ActivityDetail> $candidates */
        $candidates = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.start_date_local', '<=', $minDate)
            ->whereBetween('activity_details.distance', [$distanceLo, $distanceHi])
            ->whereNotNull('activity_details.start_date_local')
            ->whereNotNull('activity_details.moving_time')
            ->orderBy('activity_details.start_date_local') // ASC — oldest first wins
            ->select('activity_details.*')
            ->get();

        foreach ($candidates as $past) {
            $pastPace = $this->paceSecPerKm($past);
            if ($pastPace === null || $this->paceBand($pastPace) !== $band) {
                continue;
            }
            if (! $this->isWithinTempTolerance($detail, $past)) {
                continue;
            }

            // The SQL above filters whereNotNull('start_date_local') — assert
            // narrows the type for PHPStan without a misleading runtime guard.
            assert($past->start_date_local !== null);

            return [
                'past' => $past,
                'pace_diff_sec' => round($pastPace - $currentPaceSec, 1),
                'hr_diff_bpm' => $this->hrDiffBpm($detail, $past),
                'days_ago' => (int) $past->start_date_local->copy()->startOfDay()
                    ->diffInDays($startDate->copy()->startOfDay()),
            ];
        }

        return null;
    }

    public function paceBand(float $secPerKm): string
    {
        return match (true) {
            $secPerKm >= self::RECOVERY_PACE_FLOOR_SEC => self::BAND_RECOVERY,
            $secPerKm >= self::EASY_PACE_FLOOR_SEC => self::BAND_EASY,
            default => self::BAND_THRESHOLD,
        };
    }

    private function paceSecPerKm(ActivityDetail $detail): ?float
    {
        $distance = (float) ($detail->distance ?? 0);
        $movingTime = (int) ($detail->moving_time ?? 0);
        if ($distance <= 0 || $movingTime <= 0) {
            return null;
        }

        return $movingTime / ($distance / 1000);
    }

    private function isWithinTempTolerance(ActivityDetail $current, ActivityDetail $past): bool
    {
        // When either side has no weather, skip the temp filter — we'd
        // rather match without it than throw away a useful comparison.
        if ($current->weather_temp_c === null || $past->weather_temp_c === null) {
            return true;
        }

        return abs($current->weather_temp_c - $past->weather_temp_c) <= self::TEMP_TOLERANCE_C;
    }

    private function hrDiffBpm(ActivityDetail $current, ActivityDetail $past): ?float
    {
        if ($current->average_heartrate === null || $past->average_heartrate === null) {
            return null;
        }

        return round((float) $current->average_heartrate - (float) $past->average_heartrate, 1);
    }
}
