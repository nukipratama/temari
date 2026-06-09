<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunnerProfile>
 */
class RunnerProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'max_hr' => 180,
            'resting_hr' => 55,
            'hr_zones' => [
                'Z1' => ['lo' => 116, 'hi' => 138],
                'Z2' => ['lo' => 138, 'hi' => 154],
                'Z3' => ['lo' => 154, 'hi' => 168],
                'Z4' => ['lo' => 168, 'hi' => 176],
                'Z5' => ['lo' => 176, 'hi' => 999],
            ],
            'optimal_cadence_spm' => 170,
        ];
    }
}
