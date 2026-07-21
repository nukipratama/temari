<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Telegram\TelegramReplies;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The one-off "test notification" from the Aku page, so a user can confirm their
 * notification channels work without waiting for a run. Channel-agnostic by
 * design: `via()` fans out to every wired channel (Telegram if connected, web
 * push if subscribed), so the single "Kirim tes" action reaches every channel the
 * user has. Never sends on the shared demo identity.
 */
class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        if ($notifiable->is_demo) {
            return [];
        }

        return app(ChannelRouter::class)->channelsFor($notifiable);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return new TelegramMessage(text: TelegramReplies::test());
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title('🔔 Tes notifikasi')
            ->body(TelegramReplies::test())
            ->icon('/icon-192.png')
            // Mirror the real push: high urgency so the test is a truthful delivery signal.
            ->options(['urgency' => 'high']);
    }
}
