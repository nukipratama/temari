<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\RaceGoal;
use Illuminate\Support\Carbon;

/**
 * Race week and the {@see TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE} weeks before it, which
 * no time trial may reach, and the distance-scaled window an athlete's override may not
 * reach ({@see self::locksOverride()}).
 */
final class PreRaceWindow
{
    public const int SHORT_RACE_MAX_M = 10_000;

    public static function contains(Carbon $weekStart, ?Carbon $raceDate, int $weeksBefore = TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE): bool
    {
        return $raceDate instanceof Carbon
            && (int) $weekStart->diffInWeeks($raceDate->copy()->startOfWeek(Carbon::MONDAY)) <= $weeksBefore;
    }

    public static function locksOverride(Carbon $weekStart, ?RaceGoal $race): bool
    {
        if (! $race instanceof RaceGoal) {
            return false;
        }

        $weeksBefore = $race->distance_m <= self::SHORT_RACE_MAX_M ? 0 : TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE;

        return self::contains($weekStart, $race->race_date, $weeksBefore);
    }
}
