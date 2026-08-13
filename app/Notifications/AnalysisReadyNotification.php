<?php

declare(strict_types=1);

namespace App\Notifications;

use Throwable;
use App\Enums\NotificationKind;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\TelegramMessage;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\RunCardImageRenderer;
use App\Services\Notifications\ChannelRouter;
use App\Services\Telegram\AnalysisMessagePresenter;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Fired from {@see \App\Services\AI\AnalysisService::markDone()} when a notifiable
 * analysis completes, and from the manual "Kirim notifikasi" controllers
 * ($force). `via()` decides per channel: an automatic push honours the recency
 * gate and the master-switch opt-in, a manual push bypasses both and reaches every
 * wired channel (Telegram if connected, web push if subscribed). Delivery +
 * idempotency live in {@see TelegramChannel} / {@see IdempotentWebPushChannel}.
 */
class AnalysisReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Analysis $analysis, public readonly bool $force = false)
    {
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
        // the demo identity's inbox-only routing. A forced send may skip the
        // *whether* gates below, but never this one: muting a channel is a
        // routing decision, not a per-message one.
        $channels = app(ChannelRouter::class)->channelsFor($notifiable);

        // A manual push bypasses the recency + opt-in gates; the automatic path
        // keeps the recency gate and the channel-neutral master-switch opt-in.
        if ($this->force) {
            return $channels;
        }

        $reachableNow = $eligibility->isRecentEnoughToAutoNotify($this->analysis)
            && $eligibility->isOptedIn($this->analysis, $notifiable);

        return $reachableNow ? $channels : [];
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $presenter = app(AnalysisMessagePresenter::class);

        return new TelegramMessage(
            text: $presenter->format($this->analysis),
            photoPng: $this->renderPostRunCard(),
            deliveryKey: $this->deliveryKey(),
            force: $this->force,
        );
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $presenter = app(AnalysisMessagePresenter::class);

        return new WebPushMessage()
            ->title($presenter->title($this->analysis))
            ->body(trim((string) $this->analysis->content))
            ->icon('/icon-192.png')
            ->data(['url' => $presenter->url($this->analysis)])
            // High urgency so the push isn't deferred by the OS in Low Power Mode.
            ->options(['urgency' => 'high']);
    }

    /**
     * The inbox row. Keyed on the analysis rather than the notification id so a
     * re-analysis ("Baca ulang", ai:self-heal) or a manual force-send updates
     * nothing instead of stacking a second row for the same run.
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

    public function forcesDelivery(): bool
    {
        return $this->force;
    }

    /**
     * What the inbox needs to replay this weeks later. A post-run row carries the
     * card id, which the existing `api.cards.replay` endpoint re-arms into a full
     * reveal, plus the rarity so the list can style the row without a join.
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

    /**
     * The rendered card PNG for a post-run notification whose activity has a
     * generated card, or null (send as text) for any other type, a card-less
     * activity, or a render failure.
     */
    private function renderPostRunCard(): ?string
    {
        if ($this->analysis->analysis_type !== AnalysisType::PostRunSpeech) {
            return null;
        }

        $card = RunCard::query()->where('activity_id', $this->analysis->subject_id)->first();
        if ($card === null) {
            return null;
        }

        try {
            return app(RunCardImageRenderer::class)->render($card);
        } catch (Throwable $e) {
            Log::warning('telegram.card_photo.render_failed', [
                'analysis_id' => $this->analysis->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
