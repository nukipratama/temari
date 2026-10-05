<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\PrCategory;
use App\Models\User;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The note dispatched by {@see \App\Console\Commands\Run\FitnessNotifyImprovementCommand}
 * when the supported race time has moved up by a meaningful step. Governed by
 * the master switch like the other notes Temari initiates.
 *
 * The inbox row's dedupe key is the athlete's day, so a re-run is one row, not two.
 */
class FitnessImprovedNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use Queueable;
    use RechecksRouteAtDelivery;

    public const int PUSH_TTL_SECONDS = 3 * 86400;

    public const string PUSH_TOPIC = 'fitness';

    private const float NAMED_DISTANCE_TOLERANCE = 0.01;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly float $raceDistanceM,
        public readonly int $supportedSec,
        public readonly int $previousSec,
        public readonly int $basisDistanceM,
        public readonly string $basisOn,
        public readonly string $notedOn,
    ) {
    }

    public static function dedupeKeyFor(string $notedOn): string
    {
        return 'fitness_improved:'.$notedOn;
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
            ->options(['TTL' => self::PUSH_TTL_SECONDS, 'topic' => self::PUSH_TOPIC]);
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::FitnessImproved,
            title: $this->title(),
            body: $this->body(),
            payload: ['url' => route('race'), 'supported_time_sec' => $this->supportedSec],
            dedupeKey: self::dedupeKeyFor($this->notedOn),
        );
    }

    private function title(): string
    {
        return "your supported {$this->distanceLabel($this->raceDistanceM)} just got quicker";
    }

    private function body(): string
    {
        $time = DurationFormatter::hms($this->supportedSec);
        $gain = DurationFormatter::hms(max(0, $this->previousSec - $this->supportedSec));
        $basis = $this->distanceLabel((float) $this->basisDistanceM);
        $on = strtolower(Carbon::parse($this->basisOn)->format('M j'));

        return "your recent runs now support {$time} for the {$this->distanceLabel($this->raceDistanceM)}, {$gain} quicker than when i last told you. "
            ."it rests on your {$basis} on {$on}, and your easy and long-run paces move up with it.";
    }

    private function distanceLabel(float $meters): string
    {
        foreach ([PrCategory::Km5, PrCategory::Km10, PrCategory::Km15, PrCategory::HalfMarathon, PrCategory::Marathon] as $category) {
            $named = (float) $category->distanceMeters();
            if (abs($meters - $named) / $named <= self::NAMED_DISTANCE_TOLERANCE) {
                return match ($category) {
                    PrCategory::HalfMarathon => 'half marathon',
                    PrCategory::Marathon => 'marathon',
                    default => strtoupper(str_replace('km', 'K', $category->value)),
                };
            }
        }

        return DistanceFormatter::kmString($meters).' km';
    }
}
