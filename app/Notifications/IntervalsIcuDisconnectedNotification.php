<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Tells the athlete their intervals.icu key stopped working, once, when the
 * sync first meets the rejection.
 *
 * Not gated on the `notifications_enabled` master switch, like
 * {@see StravaDisconnectedNotification}: it reports a broken connection
 * rather than content Temari initiates.
 */
class IntervalsIcuDisconnectedNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Carbon $brokenAt)
    {
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        return app(ChannelRouter::class)->channelsFor($notifiable);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            text: $this->title()."\n\n".$this->body()."\n\nReconnect: ".$this->url(),
        );
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::IntervalsIcuDisconnected,
            title: $this->title(),
            body: $this->body(),
            payload: ['url' => $this->url()],
            dedupeKey: 'intervals_icu_disconnected:'.$this->brokenAt->timestamp,
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => $this->url()], $notifiable->id))
            ->options(['TTL' => 7 * 86400]);
    }

    private function title(): string
    {
        return 'intervals.icu stopped taking workouts';
    }

    private function body(): string
    {
        return "intervals.icu stopped accepting your key, so today's run isn't reaching your watch. reconnect it in settings.";
    }

    /** Settings is where the reconnect form lives. */
    private function url(): string
    {
        return route('settings');
    }
}
