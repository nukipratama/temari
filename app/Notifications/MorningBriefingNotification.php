<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Today's briefing, pushed at the hour the athlete usually runs, by
 * {@see \App\Console\Commands\Notifications\MorningBriefingPushCommand}. It
 * carries a row `ai:daily-briefing` already generated at 00:01 and generates
 * nothing itself.
 *
 * Outbound only. The briefing is already on the dashboard, so a second copy of
 * it in the inbox would be a record of nothing new; what this adds is the
 * timing, which Telegram carries as well as a push. Idempotency is the shared
 * per-(analysis, channel) claim, so each channel sends at most once via
 * {@see self::deliveryKey()} — the briefing row is per athlete per day, so that
 * claim is too.
 */
class MorningBriefingNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Analysis $briefing)
    {
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        $preference = $notifiable->notificationPreference;
        if ($preference !== null && ! $preference->notifications_enabled) {
            return [];
        }

        return app(ChannelRouter::class)->outboundOnly($notifiable);
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        if ($channel === InAppChannel::class) {
            return true;
        }

        $currentUser = $notifiable->fresh();

        return $currentUser !== null && in_array($channel, $this->via($currentUser), true);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            text: "your briefing for today\n\n".trim((string) $this->briefing->content)."\n\nOpen Temari: ".route('dashboard'),
            deliveryKey: $this->deliveryKey(),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title('your briefing for today')
            ->body(trim((string) $this->briefing->content))
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => route('dashboard')], $notifiable->id))
            // High urgency: the whole point is landing at the moment they are
            // about to head out, which a deferred push misses entirely.
            ->options(['urgency' => 'high']);
    }

    /** The idempotency key: the briefing row, which is already per athlete per day. */
    public function deliveryKey(): int
    {
        return $this->briefing->id;
    }
}
