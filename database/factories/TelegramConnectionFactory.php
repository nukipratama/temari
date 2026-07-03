<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramConnection>
 */
class TelegramConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'username' => fake()->optional()->userName(),
            'notify_post_run' => true,
            'notify_weekly_recap' => true,
            'notify_monthly_recap' => true,
        ];
    }

    /**
     * A connection the user has disconnected.
     */
    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
