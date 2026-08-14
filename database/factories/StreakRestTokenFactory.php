<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StreakRestToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Override;

/**
 * @extends Factory<StreakRestToken>
 */
class StreakRestTokenFactory extends Factory
{
    /** @return array<string, mixed> */
    #[Override]
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'earned_for_week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'spent_for_week_ending' => null,
        ];
    }

    /**
     * A token already spent to forgive one runless week.
     */
    public function spentFor(Carbon $weekEnding): static
    {
        return $this->state(fn (): array => ['spent_for_week_ending' => $weekEnding->toDateString()]);
    }
}
