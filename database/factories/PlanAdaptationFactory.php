<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdaptationReason;
use App\Models\PlanAdaptation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PlanAdaptation>
 */
class PlanAdaptationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'reason' => AdaptationReason::Steady,
            'deload' => false,
            'quality_delta' => 0,
            'adherence_pct' => 100,
        ];
    }
}
