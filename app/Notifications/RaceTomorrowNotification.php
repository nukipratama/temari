<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The evening-before nudge dispatched by {@see \App\Console\Commands\Run\RaceRemindCommand}.
 * Channel-neutral like the streak nudge, and governed by the same master switch:
 * it is content Temari initiates, which is what that switch enumerates.
 *
 * The inbox row's dedupe key is the race plus its date, so a re-run — or a race
 * whose date is edited and lands on "tomorrow" again — is one row, not two.
 */
class RaceTomorrowNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return 'race_tomorrow:'.$race->id.':'.$race->race_date->toDateString();
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
            text: "{$this->title()}\n\n{$this->body($notifiable)}\n\nOpen Temari: ".route('race'),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body($notifiable))
            ->icon('/icon-192.png')
            ->data(['url' => route('race')])
            ->options(['urgency' => 'high']);
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::RaceTomorrow,
            title: $this->title(),
            body: $this->body($notifiable),
            payload: ['url' => route('race'), 'race_date' => $this->race->race_date->toDateString()],
            dedupeKey: self::dedupeKeyFor($this->race),
        );
    }

    private function title(): string
    {
        return 'race day is tomorrow';
    }

    private function body(User $notifiable): string
    {
        $distance = DistanceFormatter::kmString((float) $this->race->distance_m);
        $subject = $this->race->name === null
            ? "your {$distance} km is tomorrow."
            : "{$this->race->name} is tomorrow — {$distance} km.";

        return trim($subject.' '.$this->taperNote($notifiable).' lay your kit out tonight so morning-you has nothing left to decide.');
    }

    /**
     * What the plan already asks of today, said back rather than invented: race
     * week rests the day before the race, so an athlete who reads this in the
     * evening is being told to leave that rest alone.
     */
    private function taperNote(User $notifiable): string
    {
        $today = PlannedSession::query()
            ->where('user_id', $notifiable->id)
            ->whereDate('date', Carbon::today())
            ->value('session_type');

        return $today === SessionType::Rest ? 'the plan rests you today, so leave it rested.' : '';
    }
}
