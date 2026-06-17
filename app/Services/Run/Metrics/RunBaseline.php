<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\ActivityDetail;
use Illuminate\Support\Carbon;

/**
 * A user's recent training baseline over the rolling window ending just before a
 * given run: distance-weighted average pace, mean heart rate, and mean aerobic
 * decoupling across the prior runs. Lets a narrator frame the current run
 * against the user's own norm ("faster than your 28-day average"). Returns null
 * when the window holds no comparable runs.
 */
class RunBaseline
{
    public const int WINDOW_DAYS = 28;

    /**
     * @return array{runs:int, avg_pace_sec_per_km:int|null, avg_hr:int|null, avg_decoupling_pct:float|null}|null
     */
    public function forUserAsOf(int $userId, Carbon $asOf, ?int $excludeActivityId = null): ?array
    {
        $start = $asOf->copy()->subDays(self::WINDOW_DAYS)->startOfDay();

        $details = ActivityDetail::query()
            ->forUser($userId)
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '>=', $start)
            ->where('start_date_local', '<', $asOf)
            ->when(
                $excludeActivityId !== null,
                fn ($query) => $query->where('activity_id', '!=', $excludeActivityId),
            )
            ->get(['activity_id', 'distance', 'moving_time', 'average_heartrate', 'stream_summary']);

        if ($details->isEmpty()) {
            return null;
        }

        $totalDistance = 0.0;
        $totalTime = 0;
        $hrValues = [];
        $decouplingValues = [];

        foreach ($details as $detail) {
            if ($detail->distance !== null && $detail->moving_time !== null && $detail->moving_time > 0) {
                $totalDistance += $detail->distance;
                $totalTime += $detail->moving_time;
            }
            if ($detail->average_heartrate !== null) {
                $hrValues[] = (float) $detail->average_heartrate;
            }
            $decoupling = $detail->streamSummary()['decoupling_pct'] ?? null;
            if (is_numeric($decoupling)) {
                $decouplingValues[] = (float) $decoupling;
            }
        }

        $avgPace = PaceCalculator::secPerKm($totalDistance, $totalTime);

        return [
            'runs' => $details->count(),
            'avg_pace_sec_per_km' => $avgPace !== null ? (int) round($avgPace) : null,
            'avg_hr' => $hrValues !== [] ? (int) round(array_sum($hrValues) / count($hrValues)) : null,
            'avg_decoupling_pct' => $decouplingValues !== []
                ? round(array_sum($decouplingValues) / count($decouplingValues), 1)
                : null,
        ];
    }
}
