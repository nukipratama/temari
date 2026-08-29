<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use App\Models\TrainingPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingPreference>
 */
class TrainingPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'experience_level' => ExperienceLevel::Experienced,
            'sessions_per_week' => 4,
            'goal_type' => GoalType::Consistent,
            'run_days' => [1, 3, 5, 6],
            'long_run_day' => 6,
        ];
    }
}
