<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<UserUnlock>
 */
class UserUnlockFactory extends Factory
{
    protected $model = UserUnlock::class;

    /** @return array<string, mixed> */
    #[Override]
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'unlock_key' => 'accessory.medal_first_pr',
            'unlocked_at' => now(),
            'metadata' => null,
        ];
    }

    /**
     * An unlock the user has equipped to their mascot.
     */
    public function equipped(): static
    {
        return $this->state(fn (): array => ['equipped' => true]);
    }
}
