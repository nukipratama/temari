<?php

declare(strict_types=1);

namespace App\Services\Devtools;

use Illuminate\Support\Carbon;

/**
 * Where an athlete's month lands if the last seven days repeat. A projection,
 * not a budget: the recent run rate is the only evidence there is, so it is
 * stated as such and labelled that way in the UI.
 */
class CostForecast
{
    /**
     * @return array{month_to_date: float, projected: float, days_remaining: int, daily_rate: float}
     */
    public function project(float $monthToDate, float $lastSevenDaysCost, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $daysRemaining = $today->daysInMonth - $today->day;
        $dailyRate = $lastSevenDaysCost / 7;

        return [
            'month_to_date' => $monthToDate,
            'projected' => $monthToDate + $dailyRate * $daysRemaining,
            'days_remaining' => $daysRemaining,
            'daily_rate' => $dailyRate,
        ];
    }
}
