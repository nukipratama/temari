<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityDetail>
 */
class ActivityDetailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Elapsed matches moving by default: pace and every displayed duration
        // read elapsed, so a random gap between the two made a fixture's own
        // pace assertions depend on the seed. A test that cares about stoppage
        // time sets both explicitly.
        $movingTime = fake()->numberBetween(1800, 4200);

        return [
            'activity_id' => Activity::factory(),
            'name' => fake()->randomElement(['Morning Run', 'Afternoon Run', 'Evening Run']),
            'start_date_local' => fake()->dateTimeBetween('-3 months', 'now'),
            'distance' => fake()->randomFloat(1, 3000, 12000),
            'moving_time' => $movingTime,
            'elapsed_time' => $movingTime,
            'average_speed' => fake()->randomFloat(2, 2.4, 3.4),
            'total_elevation_gain' => fake()->randomFloat(1, 0, 100),
            'has_heartrate' => true,
            'average_heartrate' => fake()->randomFloat(1, 140, 165),
            'max_heartrate' => fake()->numberBetween(165, 180),
            'average_cadence' => fake()->randomFloat(1, 78, 88),
            'calories' => fake()->randomFloat(1, 300, 800),
            'splits_metric' => null,
            'summary_polyline' => null,
            'trimp_edwards' => fake()->randomFloat(1, 40, 120),
            'stream_summary' => null,
            'weather_temp_c' => fake()->numberBetween(22, 32),
            'weather_humidity_pct' => fake()->numberBetween(60, 95),
            'weather_rain_detected' => false,
            'vibe_state' => null,
        ];
    }
}
