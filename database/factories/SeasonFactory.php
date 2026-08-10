<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'race_goal_id' => null,
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->addWeeks(12)->toDateString(),
        ];
    }
}
