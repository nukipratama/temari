<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StravaConnection>
 */
class StravaConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'strava_athlete_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_expires_at' => now()->addHours(6),
            'scopes' => 'read,activity:read_all',
        ];
    }

    /**
     * A connection the user has deauthorized.
     */
    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
