<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\ChannelRouter;
use App\Services\Telegram\AnalysisMessagePresenter;
use App\Services\Telegram\NotificationEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Fired from {@see \App\Services\AI\AnalysisService::markDone()} when a notifiable
 * analysis completes. `via()` honours the recency gate and the master-switch
 * opt-in, then reaches every wired channel (Telegram if connected, web push if
 * subscribed). Delivery + idempotency live in {@see TelegramChannel} /
 * {@see IdempotentWebPushChannel}.
 */
class AnalysisReadyNotification extends Notification implements ShouldQueue
{
    use AppendsUnreadBadge;
    use RechecksRouteAtDelivery;
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public ?CarbonImmutable $triggeredAt = null;

    public function __construct(public readonly Analysis $analysis)
    {
        $this->triggeredAt = now()->toImmutable();
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        $eligibility = app(NotificationEligibility::class);
        if (! $eligibility->isNotifiable($this->analysis)) {
            return [];
        }

        // Where the user can be reached, including their per-channel mutes and
        // the demo identity's inbox-only routing.
        $channels = app(ChannelRouter::class)->channelsFor($notifiable);

        $reachableNow = $eligibility->isRecentEnoughToAutoNotify($this->analysis, $this->triggeredAt)
            && $eligibility->isOptedIn($this->analysis, $notifiable);

        return $reachableNow ? $channels : [];
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $presenter = app(AnalysisMessagePresenter::class);

        return new TelegramMessage(
            text: $presenter->format($this->analysis),
            deliveryKey: $this->deliveryKey(),
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $presenter = app(AnalysisMessagePresenter::class);

        return new WebPushMessage()
            ->title($presenter->title($this->analysis))
            ->body(trim((string) $this->analysis->content))
            ->icon('/icon-192.png')
            ->data($this->withUnreadBadge(['url' => $presenter->url($this->analysis)], $notifiable->id))
            // High urgency so the push isn't deferred by the OS in Low Power Mode.
            ->options(['urgency' => 'high', 'TTL' => 3 * 86400]);
    }

    /**
     * The inbox row. Keyed on the analysis rather than the notification id so a
     * re-analysis ("Reread", ai:self-heal) updates nothing instead of stacking a
     * second row for the same run.
     */
    public function toInbox(User $notifiable): ?InboxMessage
    {
        $kind = NotificationKind::forAnalysisType($this->analysis->analysis_type);
        if ($kind === null) {
            return null;
        }

        $presenter = app(AnalysisMessagePresenter::class);

        return new InboxMessage(
            kind: $kind,
            title: $presenter->title($this->analysis),
            body: trim((string) $this->analysis->content),
            payload: $this->inboxPayload($presenter),
            subjectType: $this->analysis->subject_type,
            subjectId: $this->analysis->subject_id,
            dedupeKey: 'analysis:' . $this->analysis->id,
        );
    }

    /** The idempotency key shared by every channel: the analysis id. */
    public function deliveryKey(): int
    {
        return $this->analysis->id;
    }

    /**
     * What the inbox needs to render this weeks later. A post-run row carries the
     * card id and its rarity, so the list can style the row without a join.
     * Everything else is a deep link.
     *
     * @return array<string, mixed>
     */
    private function inboxPayload(AnalysisMessagePresenter $presenter): array
    {
        $payload = [
            'analysis_id' => $this->analysis->id,
            'url' => $presenter->url($this->analysis),
        ];

        if ($this->analysis->analysis_type !== AnalysisType::PostRunSpeech) {
            return $payload;
        }

        $card = RunCard::query()->where('activity_id', $this->analysis->subject_id)->first();

        return [
            ...$payload,
            'activity_id' => $this->analysis->subject_id,
            'run_card_id' => $card?->id,
            'rarity' => $card?->rarity->value,
        ];
    }
}
