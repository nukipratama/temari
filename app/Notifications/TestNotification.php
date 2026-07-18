<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The one-off "test notification" from the Aku page, so a user can confirm their
 * notification channels work without waiting for a run. Channel-agnostic by
 * design: `via()` fans out to every wired channel (only Telegram today; web push
 * joins it in the WebPush slice), so the single "Kirim tes" action reaches every
 * channel the user has.
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
        $channels = [];

        $connection = $notifiable->telegramConnection;
        if ($connection !== null && ! $connection->isRevoked()) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return new TelegramMessage(text: TelegramReplies::test());
    }
}
