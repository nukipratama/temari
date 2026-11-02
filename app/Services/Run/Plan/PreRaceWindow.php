<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use Illuminate\Support\Carbon;

/**
 * Race week and the {@see TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE} weeks before it, which
 * neither a time trial nor an athlete's override may reach.
 */
final class PreRaceWindow
{
    public static function contains(Carbon $weekStart, ?Carbon $raceDate): bool
    {
        return $raceDate instanceof Carbon
            && (int) $weekStart->diffInWeeks($raceDate->copy()->startOfWeek(Carbon::MONDAY)) <= TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE;
    }
}
