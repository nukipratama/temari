<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use Throwable;
use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
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
 * once-only delivery claim, so a user can (re)send a run to Telegram on demand
 * from its detail page.
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

    public function handle(NotifiableAnalysis $registry, TelegramClient $client): void
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

        $this->send($registry, $client, $analysis, $connection);
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

    private function send(NotifiableAnalysis $registry, TelegramClient $client, Analysis $analysis, TelegramConnection $connection): void
    {
        try {
            $client->sendMessage($connection->chat_id, $registry->format($analysis));
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
    }
}
