<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Activity;
use App\Models\ActivityDetail;

/**
 * Every comparison this class makes is between two runs of the same user.
 *
 * Two selections sit on the same rules. {@see findMatch} serves the run-detail
 * panel and prefers the *oldest* qualifying run, so the contrast reads as
 * progress. {@see bestMatch} serves the home-screen trend and prefers the
 * *most similar* run, so the deltas it feeds the verdict are not noise from a
 * poorly comparable pairing.
 *
 * Hard rules: same pace band, distance ±500m absolute, 21 to 365 days apart,
 * and (for {@see bestMatch}) elevation within 15 m/km when both sides know it.
 * Temperature ±3°C additionally gates {@see findMatch}; the trend path uses
 * season instead, because `weather_temp_c` needs the detail pipeline and a
 * summary-state run has to stay a valid candidate.
 */
class PastYouMatcher
{
    public const string BAND_RECOVERY = 'recovery';

    public const string BAND_EASY = 'easy';

    public const string BAND_THRESHOLD = 'threshold';

    private const float DISTANCE_TOLERANCE_M = 500.0;

    private const int TEMP_TOLERANCE_C = 3;

    public const int MIN_GAP_DAYS = 21;

    /**
     * Oldest a comparison run may be. Without a ceiling the oldest-first pick
     * below reaches as far back as the account goes, and "14 seconds faster than
     * five years ago" compares the runner to a different person. A year keeps the
     * contrast wide enough to feel like progress and close enough to be theirs.
     */
    public const int MAX_GAP_DAYS = 365;

    /** A hilly run and a flat one at the same pace are not the same performance. */
    private const float ELEVATION_TOLERANCE_M_PER_KM = 15.0;

    /** Heart-rate gap at which two runs stop reading as the same kind of session. */
    private const float HR_SATURATION_BPM = 25.0;

    private const float WEIGHT_DISTANCE = 0.30;

    private const float WEIGHT_HEARTRATE = 0.25;

    private const float WEIGHT_ELEVATION = 0.20;

    private const float WEIGHT_TIME_OF_DAY = 0.15;

    private const float WEIGHT_SEASON = 0.10;

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
        $maxDate = $startDate->copy()->subDays(self::MAX_GAP_DAYS)->startOfDay();
        $distanceLo = $currentDistance - self::DISTANCE_TOLERANCE_M;
        $distanceHi = $currentDistance + self::DISTANCE_TOLERANCE_M;

        $paceExpr = '(activity_details.moving_time * 1000.0 / activity_details.distance)';

        /** @var Collection<int, ActivityDetail> $candidates */
        $candidates = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.start_date_local', '<=', $minDate)
            ->where('activity_details.start_date_local', '>=', $maxDate)
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
            'past_km' => DistanceFormatter::km((float) ($past->distance ?? 0)),
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

    /**
     * The most comparable run in $candidates, or null when none qualifies.
     * Ties break to the older run, keeping {@see findMatch}'s contrast bias.
     *
     * @param  list<ComparableRun>  $candidates
     */
    public function bestMatch(ComparableRun $current, array $candidates): ?PastYouComparison
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $score = $this->similarity($current, $candidate);
            if ($score === null) {
                continue;
            }

            if ($best === null
                || $score > $bestScore
                || ($score === $bestScore && $candidate->startedAt->lt($best->startedAt))) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best === null ? null : PastYouComparison::between($current, $best, $bestScore);
    }

    /**
     * How comparable two runs are on 0..1, reading only fields the Strava
     * summary payload carries. Null when a hard rule rejects the pairing.
     *
     * Pace itself is not scored: the pace band already establishes that the two
     * are the same kind of session, and the pace gap *within* the band is the
     * signal the verdict is measuring, so rewarding similarity there would bury
     * the very change this is asked to detect. Heart rate is scored softly for
     * the same reason.
     */
    public function similarity(ComparableRun $current, ComparableRun $past): ?float
    {
        $daysApart = $past->daysBefore($current);
        if ($daysApart < self::MIN_GAP_DAYS || $daysApart > self::MAX_GAP_DAYS) {
            return null;
        }

        if ($this->paceBand($past->paceSecPerKm) !== $this->paceBand($current->paceSecPerKm)) {
            return null;
        }

        $distanceGap = abs($current->distanceM - $past->distanceM);
        if ($distanceGap > self::DISTANCE_TOLERANCE_M) {
            return null;
        }

        $axes = [[self::WEIGHT_DISTANCE, 1.0 - $distanceGap / self::DISTANCE_TOLERANCE_M]];

        if ($current->averageHeartrate !== null && $past->averageHeartrate !== null) {
            $hrGap = abs($current->averageHeartrate - $past->averageHeartrate);
            $axes[] = [self::WEIGHT_HEARTRATE, max(0.0, 1.0 - $hrGap / self::HR_SATURATION_BPM)];
        }

        $currentElevation = $current->elevationPerKm();
        $pastElevation = $past->elevationPerKm();
        if ($currentElevation !== null && $pastElevation !== null) {
            $elevationGap = abs($currentElevation - $pastElevation);
            if ($elevationGap > self::ELEVATION_TOLERANCE_M_PER_KM) {
                return null;
            }
            $axes[] = [self::WEIGHT_ELEVATION, 1.0 - $elevationGap / self::ELEVATION_TOLERANCE_M_PER_KM];
        }

        $axes[] = [self::WEIGHT_TIME_OF_DAY, 1.0 - $this->timeOfDayGap($current, $past) / (12 * 60)];
        $axes[] = [self::WEIGHT_SEASON, 1.0 - $this->seasonGap($current, $past) / 6];

        $weighted = 0.0;
        $totalWeight = 0.0;
        foreach ($axes as [$weight, $score]) {
            $weighted += $weight * $score;
            $totalWeight += $weight;
        }

        return $weighted / $totalWeight;
    }

    /** Minutes apart on the clock, wrapping midnight, so 23:30 and 00:30 read as an hour. */
    private function timeOfDayGap(ComparableRun $current, ComparableRun $past): float
    {
        $gap = abs($current->minuteOfDay() - $past->minuteOfDay());

        return (float) min($gap, 24 * 60 - $gap);
    }

    /** Months apart on the calendar ring, the summary-safe stand-in for the temperature gate. */
    private function seasonGap(ComparableRun $current, ComparableRun $past): float
    {
        $gap = abs($current->month() - $past->month());

        return (float) min($gap, 12 - $gap);
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
