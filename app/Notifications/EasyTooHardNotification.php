<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use App\Services\Run\Plan\NextWeekOutlook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

class EasyTooHardNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;
    use RechecksRouteAtDelivery;

    public const string ONE_MORE = NextWeekOutlook::ONE_MORE;

    public const string SET = NextWeekOutlook::SET;

    public const string LOCKED = 'locked';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @param  array{state: string, egregious: bool, hot_days: list<array{date: string, session_type: string, over_cap_minutes: int}>, drop: array{date: string, session_type: string, time_trial: bool}|null, blocker: string|null}  $outlook  from {@see NextWeekOutlook::for()}
     * @param  string|null  $causeDate  the run that set off a warning; a Sunday lock names every hot day
     */
    public function __construct(
        public readonly string $weekStart,
        public readonly string $state,
        public readonly array $outlook,
        public readonly ?string $causeDate = null,
    ) {
        $this->staleAfter = Carbon::parse($weekStart)->endOfWeek(Carbon::SUNDAY);
    }

    public static function dedupeKeyFor(Carbon $weekStart, string $state): string
    {
        return 'easy_too_hard:'.$weekStart->toDateString().':'.$state;
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
            text: "{$this->title()}\n\n{$this->body()}\n\nOpen Temari: ".route('plan'),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => route('plan')], $notifiable))
            ->options(['TTL' => 2 * 86400]);
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::EasyTooHard,
            title: $this->title(),
            body: $this->body(),
            payload: ['url' => route('plan')],
            dedupeKey: self::dedupeKeyFor(Carbon::parse($this->weekStart), $this->state),
        );
    }

    private function title(): string
    {
        $drop = $this->outlook['drop'];
        if ($drop === null) {
            return match (true) {
                $this->state === self::ONE_MORE => 'An easy run ran hot',
                count($this->outlook['hot_days']) > 1 => 'Easy runs ran hot this week',
                default => 'An easy run ran hot this week',
            };
        }
        $session = ucfirst(NextWeekOutlook::dropLabel($drop));

        return match ($this->state) {
            self::ONE_MORE => "{$session} next week is at risk",
            self::SET => "Next week, {$session} becomes an easy run",
            default => "Next week, {$session} is an easy run",
        };
    }

    private function body(): string
    {
        $hotDays = $this->outlook['hot_days'];
        $drop = $this->outlook['drop'];
        $dropLabel = $drop === null ? null : NextWeekOutlook::dropLabel($drop);

        if ($this->state === self::LOCKED) {
            return NextWeekOutlook::hotDaysLine($hotDays).' this week. '
                ."{$dropLabel} next week is an easy run. ".NextWeekOutlook::bringBackLine($this->outlook['blocker']);
        }

        $cause = array_find($hotDays, fn (array $day): bool => $day['date'] === $this->causeDate) ?? end($hotDays);
        $lines = [$cause === false ? 'an easy run went past your heart-rate cap.' : NextWeekOutlook::hotDayLine($cause).'.'];
        if ($this->state === self::ONE_MORE) {
            $lines[] = $dropLabel === null
                ? 'keep the rest of this week easy.'
                : "one more easy day like it this week turns {$dropLabel} next week into an easy run. ".NextWeekOutlook::bringBackLine($this->outlook['blocker'], would: true);

            return implode(' ', $lines);
        }

        $lines[] = $this->outlook['egregious'] && count($hotDays) === 1
            ? 'that is far enough over to count for the whole week.'
            : 'that is '.count($hotDays).' easy days over it this week.';
        $lines[] = $dropLabel === null
            ? 'next week has no workout to lose.'
            : "{$dropLabel} next week becomes an easy run. ".NextWeekOutlook::bringBackLine($this->outlook['blocker']);
        $lines[] = 'keep the rest of this week easy.';

        return implode(' ', $lines);
    }
}
