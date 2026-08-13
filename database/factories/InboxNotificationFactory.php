<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationKind;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InboxNotification>
 */
class InboxNotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => NotificationKind::PostRun,
            'subject_type' => null,
            'subject_id' => null,
            'title' => 'Your run is in! 🏁',
            'body' => 'nice and steady out there.',
            'payload' => null,
            'dedupe_key' => (string) Str::uuid(),
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }
}
