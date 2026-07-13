<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => fake()->imageUrl(192, 192, 'people'),
            'is_demo' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function withStravaConnection(): static
    {
        return $this->has(StravaConnection::factory(), 'stravaConnection');
    }

    public function demo(): static
    {
        return $this->state(['is_demo' => true]);
    }

    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }
}
