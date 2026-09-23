<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PlanAdaptation;
use App\Models\Season;
use App\Services\Run\Plan\SustainedAheadOfRacePace;
use Illuminate\Support\Carbon;

/**
 * The current training arc: race-oriented or self-scaled, its window, and
 * the season goals it's tracking toward, and the current week's recorded
 * adjustment.
 */
final class PlanSeasonTool extends NoArgumentTool
{
    public function __construct(
        private readonly Season $season,
        private readonly SustainedAheadOfRacePace $sustainedAheadOfRacePace,
    ) {
    }

    public function name(): string
    {
        return 'get_season';
    }

    public function description(): string
    {
        return 'This training arc: its start/end dates, whether it is building toward a named '
            .'race or is self-scaled (no race set), the season goals it is tracking, and whether '
            .'the athlete has been sustained ahead of race pace, and the current week adjustment.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $race = $this->season->raceGoal;
        $currentWeekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

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
            // Held for SustainedAheadOfRacePace::SUSTAINED_WEEKS consecutive
            // evaluated weeks — never true for a self-scaled season, which has
            // no race pace to be ahead of.
            'sustained_ahead_of_race_pace' => $this->sustainedAheadOfRacePace->forUser($this->season->user_id, $currentWeekStart),
            'current_week_adaptation' => $this->currentWeekAdaptation($currentWeekStart),
        ];
    }

    /** @return array<string, bool|int|string>|null */
    private function currentWeekAdaptation(Carbon $weekStart): ?array
    {
        $adaptation = PlanAdaptation::query()
            ->where('user_id', $this->season->user_id)
            ->where('week_start', $weekStart->toDateString())
            ->first();

        return $adaptation === null ? null : [
            'reason' => $adaptation->reason->value,
            'deload' => $adaptation->deload,
            'adherence_pct' => $adaptation->adherence_pct,
            'stimulus_adherence_pct' => $adaptation->stimulus_adherence_pct,
            'increases_held' => $adaptation->increases_held,
        ];
    }
}
