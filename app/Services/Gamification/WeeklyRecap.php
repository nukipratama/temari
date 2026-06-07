<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use Illuminate\Contracts\Support\Arrayable;
use Override;

/**
 * Render-ready "Minggu Kamu" weekly recap for the dashboard. Shaped (not a raw
 * model) so the Inertia payload stays a flat, predictable contract the React
 * RecapCard can consume without re-deriving anything.
 *
 * @phpstan-type BestCard array{
 *     id: int,
 *     rarity: string,
 *     special_move: string,
 *     mood: string|null,
 *     distance_km: float|null,
 *     polyline: string|null,
 *     date: string|null,
 * }
 * @phpstan-type NearestGoal array{
 *     id: string,
 *     title: string,
 *     current: int|float,
 *     target: int|float,
 *     unit: string,
 *     ratio: float,
 *     remainder_label: string,
 * }
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WeeklyRecap implements Arrayable
{
    /**
     * @param  string  $weekStart  Monday of the current week (YYYY-MM-DD).
     * @param  string  $weekEnd  Sunday of the current week (YYYY-MM-DD).
     * @param  int|null  $deltaPct  KM change vs last week as a signed whole percent, or null when there is no comparable last week (no prior snapshot or last-week km was 0).
     * @param  BestCard|null  $bestCard
     * @param  NearestGoal|null  $nearestGoal
     */
    public function __construct(
        public string $weekStart,
        public string $weekEnd,
        public float $thisWeekKm,
        public int $thisWeekRuns,
        public float $lastWeekKm,
        public ?int $deltaPct,
        public int $streakWeeks,
        public ?array $bestCard,
        public ?array $nearestGoal,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'week_start' => $this->weekStart,
            'week_end' => $this->weekEnd,
            'this_week_km' => $this->thisWeekKm,
            'this_week_runs' => $this->thisWeekRuns,
            'last_week_km' => $this->lastWeekKm,
            'delta_pct' => $this->deltaPct,
            'streak_weeks' => $this->streakWeeks,
            'best_card' => $this->bestCard,
            'nearest_goal' => $this->nearestGoal,
        ];
    }
}
