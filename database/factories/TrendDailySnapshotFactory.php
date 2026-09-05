<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TrendDailySnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrendDailySnapshot>
 */
class TrendDailySnapshotFactory extends Factory
{
    /**
     * Monotonic day offset so each snapshot gets a distinct snapshot_date. The
     * table is unique on (user_id, snapshot_date); a random date collides
     * across two snapshots for the same user (birthday paradox → a flaky
     * unique violation).
     */
    private static int $daySequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'snapshot_date' => now()->subDays(self::$daySequence++)->format('Y-m-d'),
            'vdot' => fake()->randomFloat(1, 35, 55),
            'pace_variability_sec' => fake()->randomFloat(1, 2, 25),
        ];
    }
}
