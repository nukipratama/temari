<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes maintainer-facing alerts to every `is_admin` user's connected Telegram
 * chat, so a solo operator sees a paused pipeline, a dead-lettered block, or a
 * dead scheduler as a push instead of discovering it days later. There is no
 * OWNER_TELEGRAM_CHAT_ID env: the alert target is the set of admins (E0-0), so it
 * is multi-admin ready and grants/revokes with the `is_admin` flag.
 *
 * Best-effort and self-contained: a no-op when Telegram is unconfigured, and a
 * per-chat send failure is logged, never thrown, so an alert can never fail the
 * job/command it is reporting on.
 */
class MaintainerAlerter
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly AppConfig $config,
    ) {
    }

    /**
     * A block just crossed into dead-letter (ai:self-heal gave up after burning
     * the retry budget). Fired from {@see AnalysisService::markFailed()} only at
     * the crossing (attempts reaching MAX), so it pushes once per dead-letter, not
     * once per failed attempt.
     */
    public function deadLettered(Analysis $row): void
    {
        $this->broadcast(
            "Ada blok AI yang nyerah setelah dicoba berkali-kali: {$row->analysis_type->value}. "
            .'Buka /ai-usage buat coba lagi manual ya.',
        );
    }

    /**
     * Alert on a generation pause on/off transition, with the reason. Compares the
     * current {@see AnalysisService::pauseReason()} to the last one alerted (stored
     * durably) and pushes only on a change, so an ongoing pause is not re-sent on
     * every hourly self-heal run. A null reason means generation resumed.
     */
    public function syncPauseState(?string $reason): void
    {
        $stored = $this->config->get(AppConfigKey::AiLastPauseReason);
        $previous = is_string($stored) ? $stored : null;

        if ($reason === $previous) {
            return;
        }

        $this->config->set(AppConfigKey::AiLastPauseReason, $reason);
        $this->broadcast($this->pauseMessage($reason));
    }

    /**
     * A scheduled command failed. Wired via `->onFailure()` in routes/console.php
     * so a dead scheduler surfaces as a push instead of silently taking down
     * background processing.
     */
    public function schedulerFailed(string $command): void
    {
        $this->broadcast("Scheduler gagal jalanin `{$command}`. Cek Horizon sama log-nya ya.");
    }

    private function pauseMessage(?string $reason): string
    {
        return match ($reason) {
            'kill_switch' => 'Temari berhenti narasi: kill switch AI lagi off.',
            'unconfigured' => 'Temari berhenti narasi: Azure OpenAI belum diisi (URI/API key kosong).',
            'cost_ceiling' => 'Temari berhenti narasi: batas biaya harian hari ini udah kelewat.',
            'config' => 'Temari berhenti narasi: config Azure kayaknya salah, cek API key sama base URL.',
            null => 'Temari udah bisa narasi lagi, pause-nya kelar.',
            default => "Temari berhenti narasi: {$reason}.",
        };
    }

    /** Send $message to every admin's active Telegram chat; no-op when unconfigured. */
    private function broadcast(string $message): void
    {
        if (blank(config('services.telegram.bot_token'))) {
            return;
        }

        $connections = TelegramConnection::query()
            ->active()
            ->whereHas('user', fn (Builder $query) => $query->where('is_admin', true))
            ->get();

        foreach ($connections as $connection) {
            try {
                $this->telegram->sendMessage($connection->chat_id, $message);
            } catch (Throwable $e) {
                Log::warning('maintainer_alert.send_failed', [
                    'chat_id' => $connection->chat_id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
