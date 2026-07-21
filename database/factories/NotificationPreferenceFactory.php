<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'post_run' => true,
            'weekly_recap' => true,
            'monthly_recap' => true,
            'telegram_enabled' => true,
            'push_enabled' => true,
        ];
    }
}
