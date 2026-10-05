<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The one ask dispatched by {@see \App\Console\Commands\Run\TimeTrialSettleCommand}
 * when a run on a time trial's day did not clear the gate on its own: only the
 * athlete knows whether it was all-out.
 *
 * The inbox row's dedupe key is the trial day's row plus its date, so a re-run is one row, not two.
 */
class TimeTrialNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;
    use RechecksRouteAtDelivery;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly PlannedSession $session)
    {
    }

    public static function dedupeKeyFor(PlannedSession $session): string
    {
        return 'time_trial:'.$session->id.':'.$session->date->toDateString();
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

        return app(ChannelRouter::class)->channelsFor($notifiable);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            text: "{$this->title()}\n\n{$this->body()}\n\nOpen Temari: ".route('dashboard'),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => route('dashboard')], $notifiable->id))
            ->options(['TTL' => 3 * 86400]);
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::TimeTrial,
            title: $this->title(),
            body: $this->body(),
            payload: ['url' => route('dashboard'), 'date' => $this->session->date->toDateString()],
            dedupeKey: self::dedupeKeyFor($this->session),
        );
    }

    private function title(): string
    {
        $distanceK = intdiv((int) ($this->session->prescription_race_context['distance_m'] ?? 0), 1000);

        return "was {$this->session->date->format('l')}'s {$distanceK}K your all-out trial?";
    }

    private function body(): string
    {
        return 'it was not quick enough to count on its own. if it was all-out, say so on home and your paces follow it. if not, nothing changes.';
    }
}
