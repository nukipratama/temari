<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The "streak at risk" nudge dispatched by {@see \App\Console\Commands\Gamification\StreakRemindCommand}.
 * Re-checks the demo flag, the connection, and the weekly-recap opt-in at send
 * time (the command already checked, but `via()` runs again per notifiable).
 */
class StreakReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $streakWeeks)
    {
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        if ($notifiable->is_demo) {
            return [];
        }

        $connection = $notifiable->telegramConnection;
        if ($connection === null || $connection->isRevoked()) {
            return [];
        }

        // Shares the channel-neutral weekly-recap opt-in; a missing row = all-on.
        $preference = $notifiable->notificationPreference;
        if ($preference !== null && ! $preference->weekly_recap) {
            return [];
        }

        return [TelegramChannel::class];
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $url = route('dashboard');

        return new TelegramMessage(
            text: "🔥 Streak lari {$this->streakWeeks} minggu kamu belum ada progres minggu ini. Sempatkan lari sebelum minggu ini berakhir, biar streak-nya nggak putus.\n\nBuka Temari: {$url}",
        );
    }
}
