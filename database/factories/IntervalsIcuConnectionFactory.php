<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntervalsIcuConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntervalsIcuConnection>
 */
class IntervalsIcuConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'athlete_id' => 'i'.fake()->unique()->numberBetween(100_000, 999_999),
            'api_key' => fake()->regexify('[a-z0-9]{24}'),
            'verified_at' => now(),
        ];
    }

    /**
     * A connection whose key intervals.icu stopped accepting.
     */
    public function broken(): static
    {
        return $this->state(fn (): array => ['broken_at' => now()]);
    }
}
