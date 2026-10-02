<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RaceGoal;
use App\Services\Gamification\SeasonGamificationContext;
use Illuminate\Support\Collection;

/**
 * The owned runs on race day that could be the race, closest distance first and longest on a tie.
 *
 * @phpstan-type Candidate array{activity_id: int, name: string|null, distance_m: float, elapsed_time_sec: int, started_at: string}
 */
final readonly class RaceOutcomeMatcher
{
    /**
     * @return Collection<int, Candidate>
     */
    public function candidates(RaceGoal $race): Collection
    {
        $tolerance = SeasonGamificationContext::RACE_DISTANCE_TOLERANCE;
        $raceDay = $race->race_date->toDateString();

        return Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $race->user_id)
            ->whereNotNull('activity_details.elapsed_time')
            ->whereDate('activity_details.start_date_local', $raceDay)
            ->whereBetween('activity_details.distance', [$race->distance_m * (1 - $tolerance), $race->distance_m * (1 + $tolerance)])
            ->orderByRaw('ABS(activity_details.distance - ?) asc', [$race->distance_m])
            ->orderByDesc('activity_details.distance')
            ->orderBy('activities.id')
            ->get(['activity_details.activity_id', 'activity_details.name', 'activity_details.distance', 'activity_details.elapsed_time', 'activity_details.start_date_local'])
            ->map(static fn (ActivityDetail $detail): array => [
                'activity_id' => $detail->activity_id,
                'name' => $detail->name,
                'distance_m' => (float) $detail->distance,
                'elapsed_time_sec' => (int) $detail->elapsed_time,
                'started_at' => $detail->start_date_local?->toDateTimeString() ?? $raceDay,
            ]);
    }
}
