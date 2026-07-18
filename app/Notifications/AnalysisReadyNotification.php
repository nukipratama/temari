<?php

declare(strict_types=1);

namespace App\Notifications;

use Throwable;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\TelegramMessage;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\RunCardImageRenderer;
use App\Services\Telegram\NotifiableAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Fired from {@see \App\Services\AI\AnalysisService::markDone()} when a notifiable
 * analysis completes, and from the manual "Kirim ke Telegram" controllers
 * ($force). `via()` decides per channel: an automatic push honours the recency
 * gate and the per-type opt-in, a manual push bypasses both and reaches every
 * wired channel. The actual delivery + idempotency lives in {@see TelegramChannel}.
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
        $registry = app(NotifiableAnalysis::class);
        if (! $registry->isNotifiable($this->analysis) || $notifiable->is_demo) {
            return [];
        }

        $connection = $notifiable->telegramConnection;
        $telegramWired = filled(config('services.telegram.bot_token'))
            && $connection !== null
            && ! $connection->isRevoked();

        if (! $telegramWired) {
            return [];
        }

        // A manual push bypasses the recency + opt-in gates; the automatic path
        // keeps both.
        if ($this->force) {
            return [TelegramChannel::class];
        }

        return $registry->isRecentEnoughToAutoNotify($this->analysis) && $registry->isOptedIn($this->analysis, $connection)
            ? [TelegramChannel::class]
            : [];
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $registry = app(NotifiableAnalysis::class);

        return new TelegramMessage(
            text: $registry->format($this->analysis),
            photoPng: $this->renderPostRunCard(),
            deliveryKey: $this->analysis->id,
            force: $this->force,
        );
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
