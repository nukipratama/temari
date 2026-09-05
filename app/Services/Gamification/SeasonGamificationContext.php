<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * The whole-season facts {@see SeasonGoalResolver} needs, resolved fresh on
 * every read (never cached — season goals are cheap to recompute and must
 * stay live). Mirrors {@see GamificationContext}'s shape, scoped to one
 * {@see Season} instead of the user's whole history.
 *
 * Only PAST days (strictly before "today") are counted, matching
 * {@see \App\Http\Controllers\PlanController}'s own `Done`/`Missed`
 * boundary — a day that hasn't finished yet can't be "completed" or
 * "honored".
 */
final readonly class SeasonGamificationContext
{
    /** Distance tolerance for matching a logged activity to the race it's supposed to be: a marathon-goal season shouldn't count a 5K as "meeting the race". */
    private const float RACE_DISTANCE_TOLERANCE = 0.10;

    /** How close to the goal time counts as "met" — see {@see \App\Services\Run\Plan\SeasonService}'s matching goal title. */
    public const float RACE_MARGIN_FRACTION = 0.05;

    public function __construct(
        public int $sessionsCompleted,
        public int $qualityCompleted,
        public float $longestLongRunKm,
        public int $restHonored,
        public bool $raceGoalMet,
        public float $ctlGrowth,
    ) {
    }

    public static function forSeason(User $user, Season $season, Carbon $today, TrainingLoad $trainingLoad): self
    {
        $boundary = $today->copy()->subDay();
        $rangeEnd = $season->ends_at->lessThan($boundary) ? $season->ends_at->copy() : $boundary;

        if ($rangeEnd->lessThan($season->starts_at)) {
            return new self(0, 0, 0.0, 0, false, 0.0);
        }

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$season->starts_at->toDateString(), $rangeEnd->toDateString()])
            ->get();

        $sessionsCompleted = 0;
        $qualityCompleted = 0;
        $longestLongRunKm = 0.0;
        $restHonored = 0;

        foreach ($sessions as $session) {
            $hasActivity = self::hasActivityOn($user->id, $session->date);

            if ($session->session_type === SessionType::Rest) {
                if (! $hasActivity) {
                    $restHonored++;
                }

                continue;
            }

            if (! $hasActivity) {
                continue;
            }

            $sessionsCompleted++;
            if (in_array($session->session_type, [SessionType::Tempo, SessionType::Interval], true)) {
                $qualityCompleted++;
            }
            if ($session->session_type === SessionType::Long) {
                $longestLongRunKm = max($longestLongRunKm, self::actualKmOn($user->id, $session->date));
            }
        }

        return new self(
            sessionsCompleted: $sessionsCompleted,
            qualityCompleted: $qualityCompleted,
            longestLongRunKm: $longestLongRunKm,
            restHonored: $restHonored,
            raceGoalMet: $season->race_goal_id !== null && self::raceGoalMet($user->id, $season),
            ctlGrowth: $season->race_goal_id === null ? self::ctlGrowth($user, $season, $today, $trainingLoad) : 0.0,
        );
    }

    private static function hasActivityOn(int $userId, Carbon $date): bool
    {
        return ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->exists();
    }

    private static function actualKmOn(int $userId, Carbon $date): float
    {
        $meters = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->max('activity_details.distance');

        return $meters === null ? 0.0 : round(((float) $meters) / 1000, 1);
    }

    private static function raceGoalMet(int $userId, Season $season): bool
    {
        $race = $season->raceGoal;
        if ($race === null) {
            return false;
        }

        $detail = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [
                $race->race_date->copy()->startOfDay(),
                $race->race_date->copy()->endOfDay(),
            ])
            ->orderByDesc('activity_details.distance')
            ->select('activity_details.distance', 'activity_details.elapsed_time')
            ->first();

        if ($detail === null || $detail->elapsed_time === null || $detail->distance === null) {
            return false;
        }

        $distanceOk = (float) $detail->distance >= $race->distance_m * (1 - self::RACE_DISTANCE_TOLERANCE);
        $timeOk = (int) $detail->elapsed_time <= $race->goal_time_sec * (1 + self::RACE_MARGIN_FRACTION);

        return $distanceOk && $timeOk;
    }

    private static function ctlGrowth(User $user, Season $season, Carbon $today, TrainingLoad $trainingLoad): float
    {
        $trend = $trainingLoad->ctlTrend($user, 100, $today);
        if ($trend === []) {
            return 0.0;
        }

        $startsAtKey = $season->starts_at->toDateString();
        $startEntry = array_find($trend, fn (array $point): bool => $point['date'] >= $startsAtKey);
        $startEntry ??= $trend[0];
        $latest = end($trend);

        return max(0.0, $latest['ctl'] - $startEntry['ctl']);
    }
}
