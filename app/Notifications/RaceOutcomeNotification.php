<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The morning-after ask dispatched by {@see \App\Console\Commands\Run\RaceOutcomeAskCommand}: a passed
 * date is not proof of a race, so Temari asks what happened instead of assuming.
 *
 * The inbox row's dedupe key is the race plus its date, so a re-run is one row, not two.
 */
class RaceOutcomeNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;
    use RechecksRouteAtDelivery;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly RaceGoal $race)
    {
    }

    public static function dedupeKeyFor(RaceGoal $race): string
    {
        return 'race_outcome:'.$race->id.':'.$race->race_date->toDateString();
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
            text: "{$this->title()}\n\n{$this->body()}\n\nOpen Temari: ".route('race'),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => route('race')], $notifiable->id))
            ->options(['TTL' => 3 * 86400]);
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::RaceOutcome,
            title: $this->title(),
            body: $this->body(),
            payload: ['url' => route('race'), 'race_date' => $this->race->race_date->toDateString()],
            dedupeKey: self::dedupeKeyFor($this->race),
        );
    }

    private function title(): string
    {
        return 'how did your race go?';
    }

    private function body(): string
    {
        $distance = DistanceFormatter::kmString((float) $this->race->distance_m);
        $subject = $this->race->name === null ? "your {$distance} km" : "{$this->race->name}";

        return "{$subject} was yesterday. confirm your run, enter your time, or tell me you did not run it. nothing is counted until you do.";
    }
}
