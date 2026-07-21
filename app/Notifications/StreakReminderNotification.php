<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The "streak at risk" nudge dispatched by {@see \App\Console\Commands\Gamification\StreakRemindCommand}.
 * Re-checks the demo flag and the weekly-recap opt-in at send time (the command
 * already checked, but `via()` runs again per notifiable). Channel-neutral like
 * the rest: it reaches every wired channel, so a user on phone push alone still
 * gets nudged.
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

        // Shares the channel-neutral weekly-recap opt-in; a missing row = all-on.
        $preference = $notifiable->notificationPreference;
        if ($preference !== null && ! $preference->weekly_recap) {
            return [];
        }

        return app(ChannelRouter::class)->channelsFor($notifiable);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $url = route('dashboard');

        return new TelegramMessage(
            text: "{$this->title()}\n\n{$this->body()}\n\nBuka Temari: {$url}",
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->data(['url' => route('dashboard')])
            // High urgency: the nudge is time-boxed to the rest of the week, so
            // the OS deferring it under Low Power Mode would defeat the point.
            ->options(['urgency' => 'high']);
    }

    private function title(): string
    {
        return "🔥 Streak lari {$this->streakWeeks} minggu kamu lagi di ujung";
    }

    private function body(): string
    {
        return 'Minggu ini belum ada progres. Sempatkan lari sebelum minggu ini berakhir, biar streak-nya nggak putus.';
    }
}
