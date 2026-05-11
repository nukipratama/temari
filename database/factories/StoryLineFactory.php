<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Activity;
use App\Models\StoryLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<StoryLine>
 */
class StoryLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            // Match the activity's owner without hydrating a full model.
            'user_id' => fn (array $attributes): int => (int) DB::table('activities')
                ->where('id', $attributes['activity_id'])
                ->value('user_id'),
            'kind' => StoryLine::KIND_POST_RUN,
            'for_date' => null,
            'mood' => fake()->randomElement(['bouncy', 'glow', 'steady', 'wobble', 'dim', 'squished']),
            'speech' => fake()->sentence(10),
            'sigil_pattern' => fake()->bothify('????'),
        ];
    }

    public function dailyGreeting(?string $forDate = null): static
    {
        return $this->state(fn (): array => [
            'kind' => StoryLine::KIND_DAILY_GREETING,
            'activity_id' => null,
            'user_id' => User::factory(),
            'for_date' => $forDate ?? now()->toDateString(),
        ]);
    }
}
