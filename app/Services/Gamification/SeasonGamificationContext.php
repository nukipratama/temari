<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * The whole-season facts {@see SeasonGoalResolver} needs, resolved fresh on
 * every read (never cached — season goals are cheap to recompute and must
 * stay live). Scoped to one {@see Season} rather than the user's whole
 * history.
 *
 * Only PAST days (strictly before "today") are counted, matching
 * {@see \App\Services\Run\Plan\PlanPageAssembler}'s own `Done`/`Missed`
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
        public float $peakWeeklyKm,
    ) {
    }

    public static function forSeason(User $user, Season $season, Carbon $today, TrainingLoad $trainingLoad): self
    {
        $boundary = $today->copy()->subDay();
        $rangeEnd = $season->ends_at->lessThan($boundary) ? $season->ends_at->copy() : $boundary;

        if ($rangeEnd->lessThan($season->starts_at)) {
            return new self(0, 0, 0.0, 0, false, 0.0, 0.0);
        }

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$season->starts_at->toDateString(), $rangeEnd->toDateString()])
            ->get();

        $kmByDay = self::actualKmByDay($user->id, $season->starts_at, $rangeEnd);

        $sessionsCompleted = 0;
        $qualityCompleted = 0;
        $longestLongRunKm = 0.0;
        $restHonored = 0;

        foreach ($sessions as $session) {
            $hasActivity = array_key_exists((string) $session->date->toDateString(), $kmByDay);

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
            if (in_array($session->session_type, [SessionType::Tempo, SessionType::Interval, SessionType::Race], true)) {
                $qualityCompleted++;
            }
            if ($session->session_type === SessionType::Long) {
                $longestLongRunKm = max($longestLongRunKm, $kmByDay[$session->date->toDateString()]);
            }
        }

        return new self(
            sessionsCompleted: $sessionsCompleted,
            qualityCompleted: $qualityCompleted,
            longestLongRunKm: $longestLongRunKm,
            restHonored: $restHonored,
            raceGoalMet: $season->race_goal_id !== null && self::raceGoalMet($user->id, $season),
            ctlGrowth: $season->race_goal_id === null ? self::ctlGrowth($user, $season, $today, $trainingLoad) : 0.0,
            peakWeeklyKm: $season->race_goal_id !== null ? self::peakWeeklyKm($user->id, $season) : 0.0,
        );
    }

    private static function peakWeeklyKm(int $userId, Season $season): float
    {
        return (float) WeeklySnapshot::query()
            ->where('user_id', $userId)
            ->whereBetween('week_ending', [$season->starts_at->toDateString(), $season->ends_at->toDateString()])
            ->max('distance_km');
    }

    /** @return array<string, float> local date (Y-m-d) => longest logged distance that day, in km */
    private static function actualKmByDay(int $userId, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->selectRaw('DATE(activity_details.start_date_local) as day, MAX(activity_details.distance) as max_distance')
            ->groupBy('day')
            ->toBase()
            ->get();

        $kmByDay = [];
        foreach ($rows as $row) {
            /** @var object{day: string, max_distance: float|string|null} $row */
            $kmByDay[$row->day] = $row->max_distance === null ? 0.0 : round(((float) $row->max_distance) / 1000, 1);
        }

        return $kmByDay;
    }

    private static function raceGoalMet(int $userId, Season $season): bool
    {
        $race = $season->raceGoal;
        if ($race === null) {
            return false;
        }

        $detail = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
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
