<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklySnapshot>
 */
class WeeklySnapshotFactory extends Factory
{
    /**
     * Monotonic week offset so each snapshot gets a distinct week_ending. The table
     * is unique on (user_id, week_ending); a random date collides across two
     * snapshots for the same user (birthday paradox → a flaky unique violation).
     */
    private static int $weekSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $weeklyTrimp = fake()->randomFloat(1, 100, 600);
        $monotony = fake()->randomFloat(2, 0.5, 1.8);
        $distanceKm = fake()->randomFloat(1, 10, 50);

        return [
            'user_id' => User::factory(),
            'week_ending' => now()->endOfWeek()->subWeeks(self::$weekSequence++)->format('Y-m-d'),
            'distance_km' => $distanceKm,
            'runs' => fake()->numberBetween(2, 6),
            // ~5:00-7:00 /km worth of moving time for the week's distance.
            'moving_time_sec' => (int) round($distanceKm * fake()->numberBetween(300, 420)),
            'weekly_trimp' => $weeklyTrimp,
            'atl_7d' => fake()->randomFloat(1, 20, 80),
            'ctl_42d' => fake()->randomFloat(1, 20, 60),
            'form' => fake()->randomFloat(1, -30, 15),
            'form_status' => fake()->randomElement(['fresh', 'optimal', 'fatigued', 'overreaching']),
            'avg_decoupling' => fake()->randomFloat(2, -5, 10),
            'monotony' => $monotony,
            'strain' => round($weeklyTrimp * $monotony, 1),
        ];
    }
}
