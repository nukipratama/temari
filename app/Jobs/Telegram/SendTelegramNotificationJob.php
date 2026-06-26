<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use Throwable;
use App\Models\AI\Analysis;
use App\Services\Telegram\NotifiableAnalysis;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Fanned out from {@see \App\Services\AI\AnalysisService::markDone()} when a
 * notifiable analysis completes. Resolves the user, honours the demo exclusion /
 * opt-in / connection guards, and sends the narration to Telegram exactly once
 * (the telegram_deliveries unique claim makes a Horizon retry idempotent).
 */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $analysisId)
    {
    }

    public function handle(NotifiableAnalysis $registry, TelegramClient $client): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || ! $registry->isNotifiable($analysis)) {
            return;
        }

        $user = $registry->resolveUser($analysis);
        if ($user === null || $user->is_demo) {
            return;
        }

        $connection = $user->telegramConnection;
        if ($connection === null || $connection->isRevoked()) {
            return;
        }

        if (! $registry->isOptedIn($analysis, $connection)) {
            return;
        }

        // Claim the delivery before sending. insertOrIgnore is atomic on the
        // unique analysis_id, so a racing retry that already claimed it gets 0
        // rows and bails before re-sending.
        $claimed = DB::table('telegram_deliveries')->insertOrIgnore([
            'analysis_id' => $analysis->id,
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return;
        }

        try {
            $client->sendMessage($connection->chat_id, $registry->format($analysis));
        } catch (Throwable $e) {
            // Release the claim so the job's retry can resend.
            DB::table('telegram_deliveries')->where('analysis_id', $analysis->id)->delete();

            throw $e;
        }
    }
}
