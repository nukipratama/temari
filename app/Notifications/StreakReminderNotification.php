<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The "streak at risk" nudge dispatched by {@see \App\Console\Commands\Gamification\StreakRemindCommand}.
 * Re-checks the notification master switch at send time (the command already
 * checked, but `via()` runs again per notifiable). Channel-neutral like the
 * rest: it reaches every wired channel, so a user on phone push alone still
 * gets nudged. Demo routing is the router's call, not this notification's.
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
        // The nudge is named in the master switch's own description, so it is
        // governed by it rather than piggybacking a recap flag; missing row = all-on.
        $preference = $notifiable->notificationPreference;
        if ($preference !== null && ! $preference->notifications_enabled) {
            return [];
        }

        return app(ChannelRouter::class)->channelsFor($notifiable);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $url = route('dashboard');

        return new TelegramMessage(
            text: "{$this->title()}\n\n{$this->body()}\n\nOpen Temari: {$url}",
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

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::StreakReminder,
            title: $this->title(),
            body: $this->body(),
            payload: ['streak_weeks' => $this->streakWeeks, 'url' => route('dashboard')],
        );
    }

    private function title(): string
    {
        return "🔥 Your {$this->streakWeeks}-week streak is on the edge";
    }

    private function body(): string
    {
        return "No runs yet this week. Get one in before it's over so the streak doesn't break.";
    }
}
