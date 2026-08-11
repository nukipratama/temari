<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceGoal>
 */
class RaceGoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'race_date' => now()->addWeeks(12)->toDateString(),
            'distance_m' => 10_000,
            'goal_time_sec' => 3_000,
            'name' => $this->faker->city().' 10K',
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()]);
    }
}
