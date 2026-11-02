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

    public const string ONE_MORE = 'one_more';

    public const string SET = 'set';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $weekStart,
        public readonly string $state,
        public readonly bool $egregious,
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
        return $this->state === self::SET ? 'Next week loses a quality session' : 'An easy run ran hot';
    }

    private function body(): string
    {
        if ($this->state === self::ONE_MORE) {
            return 'it went past your heart-rate cap. one more easy day like it this week costs next week a quality session.';
        }

        return $this->egregious
            ? 'an easy run went far enough past your heart-rate cap to cost next week a quality session on its own. keep the rest of this week easy.'
            : 'a second easy day went past your heart-rate cap, so next week carries one less quality session. keep the rest of this week easy.';
    }
}
