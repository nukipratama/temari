<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Concerns\SetsWebPushExpiry;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanChangeNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use LogicException;
use NotificationChannels\WebPush\WebPushMessage;

class PlanChangedNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;
    use RechecksRouteAtDelivery {
        shouldSend as routeStillAllows;
    }
    use SetsWebPushExpiry;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /** @param array{session_type: string, hard_minutes: int, pace_band: string|null} $was */
    public function __construct(
        public readonly string $date,
        public readonly array $was,
    ) {
        $this->staleAfter = Carbon::parse($date)->endOfDay();
    }

    public static function dedupeKeyFor(string $date): string
    {
        return "plan_change:{$date}";
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        $preference = $notifiable->notificationPreference;
        if ($notifiable->is_demo || ($preference !== null && ! $preference->notifications_enabled)) {
            return [];
        }

        return app(ChannelRouter::class)->channelsFor($notifiable);
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        $session = $this->sessionFor($notifiable);
        if (! $session instanceof PlannedSession || PlanChangeNotice::decisionOf($session) === $this->was) {
            PlanChangeNotice::release($notifiable, $this->date);

            return false;
        }

        return $this->routeStillAllows($notifiable, $channel);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $session = $this->sessionFor($notifiable) ?? throw new LogicException('shouldSend gates every send on the session.');

        return new TelegramMessage(
            text: self::title($this->date, $this->was, $session)."\n\n".self::body($session)."\n\nOpen Temari: ".route('plan'),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $session = $this->sessionFor($notifiable) ?? throw new LogicException('shouldSend gates every send on the session.');

        return new WebPushMessage()
            ->title(self::title($this->date, $this->was, $session))
            ->body(self::body($session))
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => route('plan')], $notifiable))
            ->options(['TTL' => $this->secondsUntil(Carbon::parse($this->date)->endOfDay())]);
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        $session = $this->sessionFor($notifiable) ?? throw new LogicException('shouldSend gates every send on the session.');

        return new InboxMessage(
            kind: NotificationKind::PlanChange,
            title: self::title($this->date, $this->was, $session),
            body: self::body($session),
            payload: ['url' => route('plan')],
            dedupeKey: self::dedupeKeyFor($this->date),
        );
    }

    /** @param array{session_type: string, hard_minutes: int, pace_band: string|null} $decision */
    private static function title(string $date, array $decision, PlannedSession $session): string
    {
        $day = Carbon::parse($date)->format('l');
        $was = self::label(SessionType::from($decision['session_type']));
        $minutes = $session->prescribed_hard_minutes ?? 0;
        $lostHardMinutes = $decision['hard_minutes'] > 0 && $minutes === 0;

        return match (true) {
            $session->session_type === SessionType::Rest => "{$day} is now a rest day",
            $lostHardMinutes && $session->prescription_reason === Periodizer::NO_FAST_FINISH_REASON => "{$day}'s long run no longer has a fast finish",
            $lostHardMinutes && $session->session_type !== SessionType::Long => "{$day}'s {$was} is now an easy run",
            $session->session_type->value !== $decision['session_type'] => "{$day}'s {$was} is now ".self::withArticle(self::label($session->session_type)),
            $lostHardMinutes => "{$day}'s long run is now at easy pace",
            $minutes !== $decision['hard_minutes'] => "{$day}'s {$was} is now {$minutes} hard minutes",
            default => "{$day}'s {$was} is now at {$session->prescribed_pace_band?->value} pace",
        };
    }

    private static function body(PlannedSession $session): string
    {
        $reason = $session->prescription_reason;
        $lines = [match ($reason) {
            null => 'Open the plan to see the week as it stands.',
            Periodizer::BUDGET_SPENT_REASON, Periodizer::NO_FAST_FINISH_REASON => "This week's hard-minute budget is spent.",
            default => ucfirst($reason).'.',
        }];

        $budget = $session->prescription_budget;
        if ($budget !== null) {
            $cost = $budget['needed_minutes'] - $budget['left_minutes'];
            $lines[] = $cost > 0
                ? "Bring it back from the plan for {$cost} hard min over this week's cap."
                : 'Bring it back from the plan.';
        }

        return implode(' ', $lines);
    }

    private function sessionFor(User $notifiable): ?PlannedSession
    {
        return PlannedSession::query()
            ->where('user_id', $notifiable->id)
            ->where('date', $this->date)
            ->where('status', PlannedSessionStatus::Planned)
            ->where('pinned', false)
            ->first();
    }

    private static function label(SessionType $type): string
    {
        return match ($type) {
            SessionType::Easy => 'easy run',
            SessionType::Long => 'long run',
            SessionType::Tempo => 'tempo',
            SessionType::Interval => 'interval session',
            SessionType::Rest => 'rest day',
            SessionType::Race => 'race',
        };
    }

    private static function withArticle(string $label): string
    {
        return (in_array($label[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').$label;
    }
}
