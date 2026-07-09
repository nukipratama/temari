<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use Illuminate\Database\Eloquent\Collection;
use App\Models\Activity;
use App\Models\ActivityDetail;

// Match rules: same pace-band, distance ±500m absolute, temp ±3°C (missing
// on either side passes), ≥21 days apart. Of qualifying candidates, prefer
// the oldest.
class PastYouMatcher
{
    public const string BAND_RECOVERY = 'recovery';

    public const string BAND_EASY = 'easy';

    public const string BAND_THRESHOLD = 'threshold';

    private const float DISTANCE_TOLERANCE_M = 500.0;

    private const int TEMP_TOLERANCE_C = 3;

    private const int MIN_GAP_DAYS = 21;

    /** Pace-band edges in sec/km. */
    private const int RECOVERY_PACE_FLOOR_SEC = 450; // > 7:30/km

    private const int EASY_PACE_FLOOR_SEC = 390;     // > 6:30/km

    /**
     * @return array{
     *   past: ActivityDetail,
     *   pace_diff_sec: float,
     *   time_diff_sec: float,
     *   hr_diff_bpm: float|null,
     *   days_ago: int,
     * }|null
     */
    public function findMatch(Activity $activity, ActivityDetail $detail): ?array
    {
        $currentPaceSec = $detail->paceSecPerKm();
        $currentDistance = (float) ($detail->distance ?? 0);
        $startDate = $detail->start_date_local;

        if ($currentPaceSec === null || $currentDistance <= 0 || $startDate === null) {
            return null;
        }

        $band = $this->paceBand($currentPaceSec);
        $minDate = $startDate->copy()->subDays(self::MIN_GAP_DAYS)->endOfDay();
        $distanceLo = $currentDistance - self::DISTANCE_TOLERANCE_M;
        $distanceHi = $currentDistance + self::DISTANCE_TOLERANCE_M;

        $paceExpr = '(activity_details.moving_time * 1000.0 / activity_details.distance)';

        /** @var Collection<int, ActivityDetail> $candidates */
        $candidates = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.start_date_local', '<=', $minDate)
            ->whereBetween('activity_details.distance', [$distanceLo, $distanceHi])
            ->whereNotNull('activity_details.start_date_local')
            ->whereNotNull('activity_details.moving_time')
            ->where('activity_details.moving_time', '>', 0)
            ->where('activity_details.distance', '>', 0)
            ->when(
                $band === self::BAND_RECOVERY,
                fn ($q) => $q->whereRaw("$paceExpr >= ?", [self::RECOVERY_PACE_FLOOR_SEC]),
            )
            ->when(
                $band === self::BAND_EASY,
                fn ($q) => $q
                    ->whereRaw("$paceExpr >= ?", [self::EASY_PACE_FLOOR_SEC])
                    ->whereRaw("$paceExpr < ?", [self::RECOVERY_PACE_FLOOR_SEC]),
            )
            ->when(
                $band === self::BAND_THRESHOLD,
                fn ($q) => $q->whereRaw("$paceExpr < ?", [self::EASY_PACE_FLOOR_SEC]),
            )
            ->orderBy('activity_details.start_date_local') // ASC — oldest first wins
            ->select('activity_details.*')
            ->limit(50)
            ->get();

        $currentKm = $currentDistance / 1000;

        foreach ($candidates as $past) {
            // The SQL above filters distance > 0 AND moving_time > 0, so
            // paceSecPerKm cannot return null here — assert narrows for PHPStan.
            $pastPace = $past->paceSecPerKm();
            assert($pastPace !== null);

            if (! $this->isWithinTempTolerance($detail, $past)) {
                continue;
            }

            assert($past->start_date_local !== null);

            $paceDiffSec = $pastPace - $currentPaceSec;

            return [
                'past' => $past,
                'pace_diff_sec' => round($paceDiffSec, 1),
                'time_diff_sec' => round($paceDiffSec * $currentKm, 1),
                'hr_diff_bpm' => $this->hrDiffBpm($detail, $past),
                'days_ago' => (int) $past->start_date_local->copy()->startOfDay()
                    ->diffInDays($startDate->copy()->startOfDay()),
            ];
        }

        return null;
    }

    /**
     * Compact, LLM-safe shape of {@see findMatch}: the comparison deltas plus a
     * couple of descriptors of the matched past run, without the full
     * ActivityDetail model. `pace_diff_sec`/`time_diff_sec` are positive when the
     * current run is faster; `hr_diff_bpm` is positive when HR is higher now.
     *
     * @return array{days_ago: int, pace_diff_sec: float, time_diff_sec: float, hr_diff_bpm: float|null, past_km: float, past_date: string|null}|null
     */
    public function findMatchContext(Activity $activity, ActivityDetail $detail): ?array
    {
        $match = $this->findMatch($activity, $detail);
        if ($match === null) {
            return null;
        }

        $past = $match['past'];

        return [
            'days_ago' => $match['days_ago'],
            'pace_diff_sec' => $match['pace_diff_sec'],
            'time_diff_sec' => $match['time_diff_sec'],
            'hr_diff_bpm' => $match['hr_diff_bpm'],
            'past_km' => round((float) ($past->distance ?? 0) / 1000, 1),
            'past_date' => $past->start_date_local?->toDateString(),
        ];
    }

    public function paceBand(float $secPerKm): string
    {
        return match (true) {
            $secPerKm >= self::RECOVERY_PACE_FLOOR_SEC => self::BAND_RECOVERY,
            $secPerKm >= self::EASY_PACE_FLOOR_SEC => self::BAND_EASY,
            default => self::BAND_THRESHOLD,
        };
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
