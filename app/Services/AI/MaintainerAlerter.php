<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes maintainer-facing alerts to every `is_admin` user's connected Telegram
 * chat, so a solo operator sees a paused pipeline, a dead-lettered block, or a
 * dead scheduler as a push instead of discovering it days later.
 *
 * Best-effort and self-contained: a no-op when Telegram is unconfigured, and a
 * per-chat send failure is logged, never thrown, so an alert can never fail the
 * job/command it is reporting on.
 */
class MaintainerAlerter
{
    /**
     * Coalescing window for dead-letter alerts: every dead-letter within this
     * many seconds of the first one in a window shares its eventual flush,
     * instead of each firing its own Telegram push.
     */
    private const int DEAD_LETTER_WINDOW_SECONDS = 120;

    /** Delay before the coalesced window is flushed into one summary message. */
    private const int DEAD_LETTER_FLUSH_DELAY_SECONDS = 90;

    private const string DEAD_LETTER_WINDOW_CACHE_KEY = 'ai.dead_letter.window_count';

    private const string DEAD_LETTER_LOCK_KEY = 'ai.dead_letter.lock';

    /** Cooldown so a broken ai_token_usages table alerts once per window, not once per failed insert. */
    private const int METERING_ALERT_COOLDOWN_SECONDS = 3600;

    private const string METERING_ALERT_COOLDOWN_CACHE_KEY = 'ai.metering.record_failed_alert_cooldown';

    /** Cooldown so an app-wide ceiling that stays tripped alerts once per window, not once per gated dispatch. */
    private const int TOTAL_CEILING_ALERT_COOLDOWN_SECONDS = 3600;

    private const string TOTAL_CEILING_ALERT_COOLDOWN_CACHE_KEY = 'ai.cost_ceiling.total_alert_cooldown';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly AppConfig $config,
    ) {
    }

    /**
     * A block just crossed into dead-letter (ai:self-heal gave up after burning
     * the retry budget). Fired from {@see AnalysisService::markFailed()} only at
     * the crossing (attempts reaching MAX). Coalesces into one summary push per
     * window instead of one per dead-letter — a rate-limit storm can dead-letter
     * many blocks within seconds, and one push per dead-letter would flood every
     * admin's Telegram. Cache::add() only succeeds for the first dead-letter in
     * a window, which is what schedules the flush; every dead-letter (first or
     * not) increments the count the flush eventually reads. Serialised against
     * flushDeadLetterWindow() via the same lock (see {@see self::withDeadLetterLock()}).
     */
    public function deadLettered(): void
    {
        $isFirstInWindow = $this->withDeadLetterLock(function (): bool {
            $isFirst = Cache::add(self::DEAD_LETTER_WINDOW_CACHE_KEY, 0, self::DEAD_LETTER_WINDOW_SECONDS);
            Cache::increment(self::DEAD_LETTER_WINDOW_CACHE_KEY);

            return $isFirst;
        });

        if ($isFirstInWindow) {
            FlushDeadLetterAlertJob::dispatch()->delay(self::DEAD_LETTER_FLUSH_DELAY_SECONDS);
        }
    }

    /**
     * Send the coalesced dead-letter count as one summary message, called by
     * {@see \App\Jobs\AI\FlushDeadLetterAlertJob}. Reads then clears the window
     * count under the same lock deadLettered() uses (see
     * {@see self::withDeadLetterLock()}) — Cache::pull() is get()-then-forget(),
     * not atomic, so without the lock a dead-letter landing between this read and
     * the clear would increment a key about to be deleted and be silently lost
     * instead of scheduling its own future flush.
     */
    public function flushDeadLetterWindow(): void
    {
        $count = $this->withDeadLetterLock(function (): int {
            $count = (int) Cache::get(self::DEAD_LETTER_WINDOW_CACHE_KEY, 0);
            Cache::forget(self::DEAD_LETTER_WINDOW_CACHE_KEY);

            return $count;
        });

        if ($count < 1) {
            return;
        }

        $blocks = $count === 1 ? '1 AI block' : "{$count} AI blocks";

        $this->broadcast("{$blocks} gave up in the last few minutes. Open /devtools/narration to retry manually.");
    }

    /**
     * Serialises the dead-letter window's read/increment/clear operations
     * against each other so deadLettered()'s increment can never land between
     * flushDeadLetterWindow()'s read and its forget (see both docblocks). On
     * the (effectively impossible) lock timeout, falls back to running $work
     * unlocked rather than dropping a dead-letter alert or blocking the
     * caller's markFailed() path.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    private function withDeadLetterLock(callable $work): mixed
    {
        try {
            return Cache::lock(self::DEAD_LETTER_LOCK_KEY, 10)->block(3, $work);
        } catch (LockTimeoutException) {
            return $work();
        }
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
        $this->broadcast("Scheduler failed to run `{$command}`. Check Horizon and the logs.");
    }

    /** A prod deploy failed its gate; pushed best-effort via the `deploy:alert` command. */
    public function deployFailed(string $reason): void
    {
        $this->broadcast("Prod deploy failed: {$reason}. Check CI and the deploy logs.");
    }

    /**
     * A token-usage insert failed. The row is swallowed by
     * {@see \App\Actions\AI\RecordTokenUsageAction} so the successful Azure call
     * that produced it is never lost, but the per-athlete cost ceiling reads
     * spend from that same table, so a broken insert silently under-counts it.
     * `Cache::add()` gates the push to once per cooldown window.
     */
    public function meteringFailed(string $exceptionClass, ?int $userId, string $kind, ?string $model): void
    {
        if (! Cache::add(self::METERING_ALERT_COOLDOWN_CACHE_KEY, true, self::METERING_ALERT_COOLDOWN_SECONDS)) {
            return;
        }

        $user = $userId !== null ? (string) $userId : 'unknown';
        $modelLabel = $model ?? 'unknown';

        $this->broadcast("Token usage metering failed ({$exceptionClass}) for user {$user}, kind {$kind}, model {$modelLabel}. The cost ceiling is under-counting spend until this is fixed.");
    }

    /**
     * The app-wide daily spend ceiling has been passed, so every athlete's
     * narration is now served rule-based until midnight. Unlike one athlete
     * exhausting their own slice, this is worth waking the maintainer for.
     */
    public function totalCeilingReached(float $todayCost, float $ceiling, int $athletes): void
    {
        if (! Cache::add(self::TOTAL_CEILING_ALERT_COOLDOWN_CACHE_KEY, true, self::TOTAL_CEILING_ALERT_COOLDOWN_SECONDS)) {
            return;
        }

        $degraded = $athletes === 1 ? '1 athlete is' : "{$athletes} athletes are";

        $this->broadcast(sprintf(
            'App-wide AI spend passed the daily ceiling: $%.2f of $%.2f. %s now served rule-based until midnight.',
            $todayCost,
            $ceiling,
            $degraded,
        ));
    }

    private function pauseMessage(?string $reason): string
    {
        return match ($reason) {
            'kill_switch' => 'Temari stopped narrating: the AI kill switch is off.',
            'auto_dispatch' => 'Temari stopped narrating: AI_AUTO_DISPATCH is off.',
            'unconfigured' => 'Temari stopped narrating: Azure OpenAI is unset (URI/API key empty).',
            'config' => 'Temari stopped narrating: the Azure config looks wrong, check the API key and base URL.',
            null => 'Temari is narrating again, the pause is over.',
            default => "Temari stopped narrating: {$reason}.",
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
