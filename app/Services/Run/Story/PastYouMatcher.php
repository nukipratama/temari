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
 * elevation within 15 m/km when both sides know it, matching planned session
 * types when both are known, and temperature within 3°C when both are known.
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

    public const float MIN_QUALITY_SCORE = 0.6;

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
     *   direction: string,
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

        $paceExpr = '(activity_details.elapsed_time * 1000.0 / activity_details.distance)';

        /** @var Collection<int, ActivityDetail> $candidates */
        $candidates = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.start_date_local', '<=', $minDate)
            ->where('activity_details.start_date_local', '>=', $maxDate)
            ->whereBetween('activity_details.distance', [$distanceLo, $distanceHi])
            ->whereNotNull('activity_details.start_date_local')
            ->whereNotNull('activity_details.elapsed_time')
            ->where('activity_details.elapsed_time', '>', 0)
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
            // The SQL above filters distance > 0 AND elapsed_time > 0, so
            // paceSecPerKm cannot return null here — assert narrows for PHPStan.
            $pastPace = $past->paceSecPerKm();
            assert($pastPace !== null);

            if (! $this->isWithinTempTolerance($detail, $past)) {
                continue;
            }

            assert($past->start_date_local !== null);

            $paceDiffSec = $pastPace - $currentPaceSec;
            $roundedPaceDiffSec = round($paceDiffSec, 1);
            $hrDiffBpm = $this->hrDiffBpm($detail, $past);

            return [
                'past' => $past,
                'pace_diff_sec' => $roundedPaceDiffSec,
                'time_diff_sec' => round($paceDiffSec * $currentKm, 1),
                'hr_diff_bpm' => $hrDiffBpm,
                'direction' => PastYouComparison::directionFor($roundedPaceDiffSec, $hrDiffBpm)->value,
                'days_ago' => (int) $past->start_date_local->copy()->startOfDay()
                    ->diffInDays($startDate->copy()->startOfDay()),
            ];
        }

        return null;
    }

    /**
     * Compact shape of {@see findMatch}: the comparison deltas plus a couple
     * of descriptors of the matched past run, without the full ActivityDetail
     * model. Originally the LLM-facing tool payload; since #1009's decision
     * that code states the comparison and narrators never do, this is now the
     * source a controller reads directly to render the fact line the UI
     * shows next to the narration, never inside it.
     *
     * Regression for #1009 (reopened): {@see findMatch}'s bare signed
     * `pace_diff_sec`/`time_diff_sec`/`hr_diff_bpm` plus a `direction`
     * composite still let a narrator invert a field's sign -- `direction`
     * is a verdict on the pair as a whole, not a guard on each number, and a
     * model reading a mixed-signal pair (slower pace, lower HR) read the pace
     * backwards to fit whichever number it decided was the headline. No
     * signed number reaches a caller here: `pace`/`time` carry an unsigned
     * magnitude plus their own `relation` (faster/slower/same, `time` always
     * agreeing with `pace` in sign since it is the same delta scaled by
     * distance), banded by {@see PastYouComparison::PACE_SIGNAL_SEC}; `hr`
     * carries bpm plus higher/lower/same, banded by
     * {@see PastYouComparison::HR_SIGNAL_BPM}. `direction` still travels
     * alongside as the composite call.
     *
     * @return array{days_ago: int, pace: array{seconds_per_km: float, relation: string}, time: array{seconds: float, relation: string}, hr: array{bpm: float, relation: string}|null, direction: string, past_km: float, past_activity_id: int, past_name: string|null}|null
     */
    public function findMatchContext(Activity $activity, ActivityDetail $detail): ?array
    {
        $match = $this->findMatch($activity, $detail);
        if ($match === null) {
            return null;
        }

        $past = $match['past'];
        $paceDiffSec = $match['pace_diff_sec'];
        $hrDiffBpm = $match['hr_diff_bpm'];
        $paceRelation = self::paceRelation($paceDiffSec);

        return [
            'days_ago' => $match['days_ago'],
            'pace' => ['seconds_per_km' => abs($paceDiffSec), 'relation' => $paceRelation],
            'time' => ['seconds' => abs($match['time_diff_sec']), 'relation' => $paceRelation],
            'hr' => $hrDiffBpm === null ? null : ['bpm' => abs($hrDiffBpm), 'relation' => self::hrRelation($hrDiffBpm)],
            'direction' => $match['direction'],
            'past_km' => DistanceFormatter::km((float) ($past->distance ?? 0)),
            'past_activity_id' => $past->activity_id,
            'past_name' => $past->name,
        ];
    }

    /** `time_diff_sec` is `pace_diff_sec` scaled by a positive distance, so it always shares this sign. */
    private static function paceRelation(float $paceDiffSec): string
    {
        return match (true) {
            $paceDiffSec >= PastYouComparison::PACE_SIGNAL_SEC => 'faster',
            $paceDiffSec <= -PastYouComparison::PACE_SIGNAL_SEC => 'slower',
            default => 'same',
        };
    }

    private static function hrRelation(float $hrDiffBpm): string
    {
        return match (true) {
            $hrDiffBpm >= PastYouComparison::HR_SIGNAL_BPM => 'higher',
            $hrDiffBpm <= -PastYouComparison::HR_SIGNAL_BPM => 'lower',
            default => 'same',
        };
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
            $quality = $this->quality($current, $candidate);
            if ($quality === null || $quality < self::MIN_QUALITY_SCORE) {
                continue;
            }

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
     * Full similarity score used to rank qualifying candidates. Null when a
     * hard rule rejects the pairing.
     *
     * Pace itself is not scored: the pace band already establishes that the two
     * are the same kind of session, and the pace gap *within* the band is the
     * signal the verdict is measuring, so rewarding similarity there would bury
     * the very change this is asked to detect. Heart rate is scored softly for
     * the same reason.
     */
    public function similarity(ComparableRun $current, ComparableRun $past): ?float
    {
        return $this->score($current, $past, includeHeartRate: true);
    }

    /** Non-outcome match quality, excluding heart rate, on a 0..1 scale. */
    public function quality(ComparableRun $current, ComparableRun $past): ?float
    {
        return $this->score($current, $past, includeHeartRate: false);
    }

    private function score(ComparableRun $current, ComparableRun $past, bool $includeHeartRate): ?float
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

        if ($current->plannedSessionType !== null
            && $past->plannedSessionType !== null
            && $current->plannedSessionType !== $past->plannedSessionType) {
            return null;
        }

        if (! $this->temperaturesMatch($current->weatherTempC, $past->weatherTempC)) {
            return null;
        }

        $axes = [[self::WEIGHT_DISTANCE, 1.0 - $distanceGap / self::DISTANCE_TOLERANCE_M]];

        if ($includeHeartRate && $current->averageHeartrate !== null && $past->averageHeartrate !== null) {
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
        return $this->temperaturesMatch($current->weather_temp_c, $past->weather_temp_c);
    }

    private function temperaturesMatch(?int $current, ?int $past): bool
    {
        return $current === null || $past === null || abs($current - $past) <= self::TEMP_TOLERANCE_C;
    }

    private function hrDiffBpm(ActivityDetail $current, ActivityDetail $past): ?float
    {
        if ($current->average_heartrate === null || $past->average_heartrate === null) {
            return null;
        }

        return round((float) $current->average_heartrate - (float) $past->average_heartrate, 1);
    }
}
