<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AI\Analysis;
use App\Models\User;
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
 * Push only. The briefing is already on the dashboard, so a second copy of it
 * in the inbox would be a record of nothing new; what this adds is the timing.
 * Idempotency is the shared per-(analysis, channel) claim
 * ({@see \App\Notifications\Channels\IdempotentWebPushChannel}) via
 * {@see self::deliveryKey()} — the briefing row is per athlete per day, so that
 * claim is too.
 */
class MorningBriefingNotification extends Notification implements ShouldQueue
{
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

        return app(ChannelRouter::class)->pushOnly($notifiable);
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title('your briefing for today')
            ->body(trim((string) $this->briefing->content))
            ->icon('/icon-192.png')
            ->data(['url' => route('dashboard')])
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
