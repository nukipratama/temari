<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveSeasonAction;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The supported time at a race's distance, one point per daily snapshot taken for that race within
 * its season. A point opens a new step label only when the effort it rests on changed.
 */
final readonly class SupportedTimeHistory
{
    public function __construct(private ResolveSeasonAction $seasons)
    {
    }

    /**
     * @return array{target_time_sec: int, points: list<array{date: string, supported_time_sec: int, source: array{distance_m: int, date: string}|null, new_source: bool}>}|null
     */
    public function for(User $user, RaceGoal $race, Carbon $today): ?array
    {
        $season = $this->seasons->latest($user->id);
        $seasonStart = $season instanceof Season && $season->race_goal_id === $race->id ? $season->starts_at->toDateString() : null;
        $snapshots = TrendDailySnapshot::query()
            ->where('user_id', $user->id)
            ->where('race_goal_id', $race->id)
            ->whereNotNull('supported_time_sec')
            ->when($seasonStart, fn ($query, string $start) => $query->where('snapshot_date', '>=', $start))
            ->where('snapshot_date', '<=', $today->toDateString())
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'supported_time_sec', 'supported_source_distance_m', 'supported_source_date']);

        if ($snapshots->count() < 2) {
            return null;
        }

        $points = [];
        $previous = null;
        foreach ($snapshots as $snapshot) {
            $source = $snapshot->supported_source_distance_m === null || $snapshot->supported_source_date === null
                ? null
                : ['distance_m' => $snapshot->supported_source_distance_m, 'date' => $snapshot->supported_source_date->toDateString()];
            $points[] = [
                'date' => $snapshot->snapshot_date->toDateString(),
                'supported_time_sec' => (int) $snapshot->supported_time_sec,
                'source' => $source,
                'new_source' => $points !== [] && $source !== null && $source !== $previous,
            ];
            $previous = $source;
        }

        return ['target_time_sec' => $race->goal_time_sec, 'points' => $points];
    }
}
