<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedSession>
 */
class PlannedSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'phase' => PlanPhase::Base,
            'session_type' => SessionType::Easy,
            'pinned' => false,
            'status' => PlannedSessionStatus::Planned,
        ];
    }

    public function rest(): static
    {
        return $this->state(fn (): array => [
            'session_type' => SessionType::Rest,
        ]);
    }

    public function pinned(): static
    {
        return $this->state(fn (): array => ['pinned' => true]);
    }

    /** As if `plan:score-compliance` already reached this row — Done, no activity logged. */
    public function scored(): static
    {
        return $this->state(fn (): array => [
            'status' => PlannedSessionStatus::Done,
            'compliance_score' => null,
            'ran_anyway' => false,
        ]);
    }
}
