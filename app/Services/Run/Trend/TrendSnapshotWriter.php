<?php

declare(strict_types=1);

namespace App\Services\Run\Trend;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\RaceAmbitionAssessor;
use Illuminate\Support\Carbon;

/**
 * Recomputes daily VDOT, pace-variability and supported race time snapshots for a user.
 */
class TrendSnapshotWriter
{
    public const int UPSERT_BATCH_SIZE = 100;

    public function __construct(
        private readonly VdotEstimator $vdotEstimator,
        private readonly RaceAmbitionAssessor $ambition,
    ) {
    }

    public function writeRange(User $user, Carbon $from, Carbon $through): int
    {
        $from = $from->copy()->startOfDay();
        $through = $through->copy()->startOfDay();

        if ($from->gt($through)) {
            return 0;
        }

        $paceVariability = $this->paceVariabilityByDate($user, $from, $through);
        $rows = [];
        $written = 0;
        $timestamp = now();

        for ($date = $from->copy(); $date->lte($through); $date->addDay()) {
            $estimate = $this->vdotEstimator->estimate($user, $date);
            $dateString = $date->toDateString();
            $race = $this->vdotEstimator->raceAsOf($user, $date);
            $ambition = $race === null ? null : $this->ambition->assess($user, $race, $date);
            $supportedSec = $ambition?->supportedTimeSec;
            $basis = $supportedSec === null ? null : $ambition->basis;

            $rows[] = [
                'user_id' => $user->id,
                'snapshot_date' => $dateString,
                'vdot' => $estimate['vdot'] ?? null,
                'pace_variability_sec' => $paceVariability[$dateString] ?? null,
                'race_goal_id' => $supportedSec === null ? null : $race->id,
                'supported_time_sec' => $supportedSec,
                'supported_source_distance_m' => $basis['distance_m'] ?? null,
                'supported_source_date' => $basis['performed_on'] ?? null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($rows) === self::UPSERT_BATCH_SIZE) {
                $written += $this->upsert($rows);
                $rows = [];
            }
        }

        return $written + $this->upsert($rows);
    }

    /**
     * @return array<string, float>
     */
    private function paceVariabilityByDate(User $user, Carbon $from, Carbon $through): array
    {
        $values = [];

        $details = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $from)
            ->where('activity_details.start_date_local', '<=', $through->copy()->endOfDay())
            ->get(['activity_details.start_date_local', 'activity_details.stream_summary']);

        foreach ($details as $detail) {
            $value = StreamSummary::fromArray($detail->stream_summary)->paceVariabilitySec();
            if ($value === null) {
                continue;
            }

            $date = $detail->start_date_local?->toDateString();
            if ($date === null) {
                continue;
            }

            $values[$date][] = $value;
        }

        return array_map(
            static fn (array $day): float => round((float) (array_sum($day) / count($day)), 1),
            $values,
        );
    }

    /**
     * @param  list<array{user_id: int, snapshot_date: string, vdot: float|null, pace_variability_sec: float|null, race_goal_id: int|null, supported_time_sec: int|null, supported_source_distance_m: int|null, supported_source_date: string|null, created_at: Carbon, updated_at: Carbon}>  $rows
     */
    private function upsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        TrendDailySnapshot::query()->upsert(
            $rows,
            ['user_id', 'snapshot_date'],
            ['vdot', 'pace_variability_sec', 'race_goal_id', 'supported_time_sec', 'supported_source_distance_m', 'supported_source_date', 'updated_at'],
        );

        return count($rows);
    }
}
