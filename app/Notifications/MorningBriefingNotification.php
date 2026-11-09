<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Concerns\SetsWebPushExpiry;
use App\Notifications\Messages\PushBody;
use App\Notifications\Messages\TelegramMessage;
use App\Console\Commands\Notifications\MorningBriefingPushCommand;
use App\Services\AI\Anchor\CitationValidator;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Today's briefing, pushed an hour before the athlete usually runs or at the
 * time they chose, by
 * {@see \App\Console\Commands\Notifications\MorningBriefingPushCommand}. It
 * carries a row `ai:daily-briefing` already generated at 00:01 and generates
 * nothing itself.
 *
 * Outbound only: the briefing is already on the dashboard, so an inbox copy would
 * record nothing new. Idempotency is the shared per-(analysis, channel) claim
 * via {@see self::deliveryKey()}, so each channel sends at most once per
 * athlete per day.
 */
class MorningBriefingNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use RechecksRouteAtDelivery;
    use SetsWebPushExpiry;
    use Queueable;

    public const int STALE_AFTER_MINUTES = 120;

    private const string TITLE = 'Your briefing for today';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Analysis $briefing)
    {
        $day = Carbon::parse($briefing->discriminator);
        $slot = MorningBriefingPushCommand::slotFor(
            $briefing->subject_id,
            NotificationPreference::query()->firstWhere('user_id', $briefing->subject_id),
        );

        $this->staleAfter = $day->copy()->addMinutes($slot + self::STALE_AFTER_MINUTES)->min($day->copy()->endOfDay());
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        $preference = $notifiable->notificationPreference;
        if ($preference !== null && (! $preference->notifications_enabled || ! $preference->briefing_push_enabled)) {
            return [];
        }

        return app(ChannelRouter::class)->outboundOnly($notifiable);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            text: self::TITLE."\n\n".trim(CitationValidator::plainWords((string) $this->briefing->narration()?->plainText()))."\n\nOpen Temari: ".route('dashboard'),
            deliveryKey: $this->deliveryKey(),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $message = new WebPushMessage()
            ->title(self::TITLE)
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => route('dashboard')], $notifiable))
            // High urgency: the whole point is landing at the moment they are
            // about to head out, which a deferred push misses entirely.
            ->options([
                'urgency' => 'high',
                'TTL' => $this->secondsUntil(Carbon::parse($this->briefing->discriminator)->endOfDay()),
                'topic' => 'briefing',
            ]);

        return PushBody::attach($message, CitationValidator::plainWords((string) $this->briefing->narration()?->plainText()));
    }

    /** The idempotency key: the briefing row, which is already per athlete per day. */
    public function deliveryKey(): int
    {
        return $this->briefing->id;
    }
}
