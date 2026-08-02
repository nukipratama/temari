<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

/**
 * A user's recent training baseline over the rolling window ending just before a
 * given run: distance-weighted average pace, mean heart rate, and mean aerobic
 * decoupling across the prior runs. Lets a narrator frame the current run
 * against the user's own norm ("faster than your 28-day average"). Returns null
 * when the window holds no comparable runs.
 */
class ResolveRunBaselineAction
{
    public const int WINDOW_DAYS = 28;

    /**
     * Answers already computed this request/job, keyed by every argument.
     *
     * The run-insight toolbox asks twice with identical arguments — once through
     * RelativeEffort, once through RecentBaselineTool — and the window scan reads
     * `stream_summary` for every run in it. The binding is `scoped()` in
     * AppServiceProvider so both reach the same instance; without that they get
     * separate ones and this memo never sees the second call.
     *
     * `excludeActivityId` is part of the key on purpose: dropping it would let a
     * run be measured against a baseline that includes itself.
     *
     * @var array<string, array{runs:int, avg_pace_sec_per_km:int|null, avg_hr:int|null, avg_decoupling_pct:float|null, avg_trimp:int|null, trimp_runs:int}|null>
     */
    private array $memo = [];

    /**
     * @return array{runs:int, avg_pace_sec_per_km:int|null, avg_hr:int|null, avg_decoupling_pct:float|null, avg_trimp:int|null, trimp_runs:int}|null
     */
    public function __invoke(int $userId, Carbon $asOf, ?int $excludeActivityId = null): ?array
    {
        $key = $userId.'|'.$asOf->toIso8601String().'|'.($excludeActivityId ?? '-');
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->compute($userId, $asOf, $excludeActivityId);
    }

    /**
     * @return array{runs:int, avg_pace_sec_per_km:int|null, avg_hr:int|null, avg_decoupling_pct:float|null, avg_trimp:int|null, trimp_runs:int}|null
     */
    private function compute(int $userId, Carbon $asOf, ?int $excludeActivityId): ?array
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
            ->get(['activity_id', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards', 'stream_summary']);

        if ($details->isEmpty()) {
            return null;
        }

        $totalDistance = 0.0;
        $totalTime = 0;
        $hrValues = [];
        $decouplingValues = [];
        $trimpValues = [];

        foreach ($details as $detail) {
            if ($detail->distance !== null && $detail->moving_time !== null && $detail->moving_time > 0) {
                $totalDistance += $detail->distance;
                $totalTime += $detail->moving_time;
            }
            if ($detail->average_heartrate !== null) {
                $hrValues[] = (float) $detail->average_heartrate;
            }
            if ($detail->trimp_edwards !== null) {
                $trimpValues[] = (float) $detail->trimp_edwards;
            }
            $decoupling = StreamSummary::fromArray($detail->streamSummary())->decouplingPct();
            if ($decoupling !== null) {
                $decouplingValues[] = $decoupling;
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
            'avg_trimp' => $trimpValues !== [] ? (int) round(array_sum($trimpValues) / count($trimpValues)) : null,
            'trimp_runs' => count($trimpValues),
        ];
    }
}
