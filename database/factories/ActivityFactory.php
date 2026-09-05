<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngestState;
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
            'ingest_state' => IngestState::Detailed,
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
            'ingest_state' => IngestState::Summary,
        ]);
    }

    /** Visible, but known only from the `/athlete/activities` summary payload. */
    public function summaryOnly(): static
    {
        return $this->state(fn (): array => [
            'analyzed_at' => now(),
            'ingest_state' => IngestState::Summary,
        ]);
    }
}
