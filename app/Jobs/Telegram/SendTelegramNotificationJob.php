<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use Throwable;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\TelegramConnection;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\RunCardImageRenderer;
use App\Services\Telegram\NotifiableAnalysis;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fanned out from {@see \App\Services\AI\AnalysisService::markDone()} when a
 * notifiable analysis completes. Resolves the user, honours the demo exclusion /
 * recency / opt-in / connection guards, and sends the narration to Telegram
 * exactly once (the telegram_deliveries unique claim makes a Horizon retry
 * idempotent).
 *
 * A manual push ($force) bypasses the recency gate, the opt-in toggle, and the
 * once-only delivery-claim CHECK, so a user can (re)send a run to Telegram on
 * demand from its detail page. It still RECORDS the claim on success, so a later
 * automatic notification for the same row (e.g. a "Baca ulang" re-analysis) is
 * deduped against it and can't fire a second push.
 */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $analysisId, public readonly bool $force = false)
    {
    }

    public function handle(NotifiableAnalysis $registry, TelegramClient $client, RunCardImageRenderer $imageRenderer): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null) {
            return;
        }

        $connection = $this->resolveConnection($registry, $analysis);
        if ($connection === null) {
            return;
        }

        // A manual push overrides the recency gate, the per-type opt-in toggle, and
        // the once-only delivery claim; the automatic path keeps all three.
        if (! $this->force && (! $this->passesAutomaticGuards($registry, $analysis, $connection) || ! $this->claimDelivery($analysis))) {
            return;
        }

        $this->send($registry, $client, $imageRenderer, $analysis, $connection);
    }

    /** Resolves the notify target, or null when any demo/connection guard fails. */
    private function resolveConnection(NotifiableAnalysis $registry, Analysis $analysis): ?TelegramConnection
    {
        if (! $registry->isNotifiable($analysis)) {
            return null;
        }

        $user = $registry->resolveUser($analysis);
        if ($user === null || $user->is_demo) {
            return null;
        }

        $connection = $user->telegramConnection;

        return $connection === null || $connection->isRevoked() ? null : $connection;
    }

    private function passesAutomaticGuards(NotifiableAnalysis $registry, Analysis $analysis, TelegramConnection $connection): bool
    {
        return $registry->isRecentEnoughToAutoNotify($analysis) && $registry->isOptedIn($analysis, $connection);
    }

    /**
     * Claim the delivery before sending. insertOrIgnore is atomic on the unique
     * analysis_id, so a racing retry that already claimed it gets 0 rows and
     * bails before re-sending.
     */
    private function claimDelivery(Analysis $analysis): bool
    {
        $claimed = DB::table('telegram_deliveries')->insertOrIgnore([
            'analysis_id' => $analysis->id,
            'created_at' => now(),
        ]);

        return $claimed !== 0;
    }

    private function send(NotifiableAnalysis $registry, TelegramClient $client, RunCardImageRenderer $imageRenderer, Analysis $analysis, TelegramConnection $connection): void
    {
        try {
            $this->deliver($client, $imageRenderer, $registry, $analysis, $connection);
        } catch (Throwable $e) {
            // A manual push has no delivery claim to dedupe a retry, so a failure
            // after the message already reached Telegram would resend on retry.
            // Treat it as one-shot: log and stop (the user can tap again).
            if ($this->force) {
                Log::warning('telegram.force_send.failed', [
                    'analysis_id' => $analysis->id,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }

            // Automatic path: release the claim so the job's retry can resend.
            DB::table('telegram_deliveries')->where('analysis_id', $analysis->id)->delete();

            throw $e;
        }

        // Reached only after a successful deliver. A manual push records the
        // delivery claim too (the automatic path already claimed before sending),
        // so a later automatic notification for the same row (e.g. a "Baca ulang"
        // re-analysis) is deduped instead of firing a second push. Best-effort and
        // outside the deliver try: a claim hiccup must not be misread as a send
        // failure (the message already went out) nor trigger a duplicate on retry.
        if ($this->force) {
            try {
                $this->claimDelivery($analysis);
            } catch (Throwable $e) {
                Log::warning('telegram.force_send.claim_failed', [
                    'analysis_id' => $analysis->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * A post-run push with a generated card goes out as a single photo message
     * (the card image + the narration as its caption); everything else, and a
     * post-run whose card isn't generated yet or fails to render, falls back to a
     * plain text message. Rendering is best-effort — a render failure degrades to
     * text rather than dropping the notification — but a real send failure
     * propagates so the caller's retry/claim-release path still applies.
     */
    private function deliver(TelegramClient $client, RunCardImageRenderer $imageRenderer, NotifiableAnalysis $registry, Analysis $analysis, TelegramConnection $connection): void
    {
        $text = $registry->format($analysis);
        $png = $this->renderPostRunCard($imageRenderer, $analysis);

        if ($png !== null) {
            $client->sendPhoto($connection->chat_id, $png, $text);

            return;
        }

        $client->sendMessage($connection->chat_id, $text);
    }

    /**
     * The rendered card PNG for a post-run notification whose activity has a
     * generated card, or null (send as text) for any other type, a card-less
     * activity, or a render failure.
     */
    private function renderPostRunCard(RunCardImageRenderer $imageRenderer, Analysis $analysis): ?string
    {
        if ($analysis->analysis_type !== AnalysisType::PostRunSpeech) {
            return null;
        }

        $card = RunCard::query()->where('activity_id', $analysis->subject_id)->first();
        if ($card === null) {
            return null;
        }

        try {
            return $imageRenderer->render($card);
        } catch (Throwable $e) {
            Log::warning('telegram.card_photo.render_failed', [
                'analysis_id' => $analysis->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
