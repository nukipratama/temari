<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Season;

/**
 * The current training arc: race-oriented or self-scaled, its window, and
 * the season goals it's tracking toward.
 */
final class PlanSeasonTool extends NoArgumentTool
{
    public function __construct(private readonly Season $season)
    {
    }

    public function name(): string
    {
        return 'get_season';
    }

    public function description(): string
    {
        return 'This training arc: its start/end dates, whether it is building toward a named '
            .'race or is self-scaled (no race set), and the season goals it is tracking.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $race = $this->season->raceGoal;

        return [
            'starts_at' => $this->season->starts_at->toDateString(),
            'ends_at' => $this->season->ends_at->toDateString(),
            'is_race_oriented' => $race !== null,
            'race_name' => $race?->name,
            'race_date' => $race?->race_date->toDateString(),
            'race_distance_m' => $race?->distance_m,
            'goals' => $this->season->goals->map(fn ($goal): array => [
                'title' => $goal->title,
                'target' => $goal->target,
                'unit' => $goal->unit,
            ])->all(),
        ];
    }
}
