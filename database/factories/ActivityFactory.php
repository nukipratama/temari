<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'strava_external_id' => fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'fetched_at' => now(),
            // Activities are ingested (analyzed) by default; the AnalyzedScope
            // hides stubs, so a default factory row must be visible. Use stub()
            // to model a freshly-synced, not-yet-ingested activity.
            'analyzed_at' => now(),
            'detail_fail_count' => 0,
        ];
    }

    public function analyzed(): static
    {
        return $this->state(fn (): array => [
            'analyzed_at' => now(),
        ]);
    }

    /** A synced-but-not-yet-ingested activity (hidden by the AnalyzedScope). */
    public function stub(): static
    {
        return $this->state(fn (): array => [
            'analyzed_at' => null,
        ]);
    }
}
