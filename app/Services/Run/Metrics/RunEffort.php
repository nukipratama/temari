<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\Effort;
use App\Models\ActivityDetail;
use App\Services\Run\Plan\PlannedSessionTypes;
use Illuminate\Support\Collection;

final class RunEffort
{
    /**
     * The matched planned session's type wins; an unplanned run falls back to its
     * Strava tag or heart-rate zones via {@see SessionIntent}. Each detail needs
     * `start_date_local`, `elapsed_time`, `workout_type` and `stream_summary` loaded.
     *
     * @param  Collection<int, ActivityDetail>  $details
     * @return array<int, Effort> keyed by activity id
     */
    public static function forDetails(int $userId, Collection $details): array
    {
        $runs = [];
        foreach ($details as $detail) {
            if ($detail->start_date_local !== null) {
                $runs[] = [$detail, $detail->start_date_local];
            }
        }
        if ($runs === []) {
            return [];
        }

        $dates = array_column($runs, 1);
        $planned = PlannedSessionTypes::byDate(
            $userId,
            min($dates)->copy()->startOfDay(),
            max($dates)->copy()->endOfDay(),
        );

        $efforts = [];
        foreach ($runs as [$detail, $startedAt]) {
            $type = $planned[$startedAt->toDateString()] ?? null;
            $efforts[$detail->activity_id] = $type !== null
                ? Effort::fromSessionType($type)
                : Effort::fromIntent(SessionIntent::forDetail($detail)['intent'], (int) $detail->elapsed_time);
        }

        return $efforts;
    }
}
