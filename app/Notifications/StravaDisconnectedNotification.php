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
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Says out loud that the Strava grant is gone, which nothing used to: the only
 * surface admitting it is the empty-runs hero, a screen an athlete with runs on
 * the dashboard never sees.
 *
 * Deliberately not gated on the `notifications_enabled` master switch, whose
 * own description names what it covers (the post-run story, the recaps, the
 * streak nudge), all of it content Temari initiates. The per-channel mutes
 * still apply, since those answer where rather than whether.
 */
class StravaDisconnectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Carbon $revokedAt)
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
            kind: NotificationKind::StravaDisconnected,
            title: $this->title(),
            body: $this->body(),
            payload: ['url' => $this->url()],
            // Keyed on the instant rather than the connection: a queued retry
            // writes one row, while a later reconnect-then-revoke writes its own.
            dedupeKey: 'strava_disconnected:'.$this->revokedAt->timestamp,
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->data(['url' => $this->url()]);
    }

    private function title(): string
    {
        return 'Strava stopped syncing';
    }

    private function body(): string
    {
        return 'your Strava connection dropped, so no new runs are coming in until you reconnect it from your profile.';
    }

    /** The profile hero is where the reconnect button lives. */
    private function url(): string
    {
        return route('profile');
    }
}
