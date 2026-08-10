<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonGoal>
 */
class SeasonGoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'title' => 'Complete 10 planned sessions',
            'metric' => 'season_sessions_completed',
            'metric_key' => null,
            'target' => 10,
            'unit' => 'sessions',
        ];
    }
}
