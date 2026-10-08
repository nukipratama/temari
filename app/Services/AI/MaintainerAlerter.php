<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Jobs\AI\SendMaintainerAlertJob;
use App\Models\ActivityDetail;
use App\Models\ScheduledTaskRun;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
 * job/command it is reporting on. Almost every alert's actual Telegram send
 * happens in {@see \App\Jobs\AI\SendMaintainerAlertJob}, queued by
 * {@see self::broadcast()}, so no outbound call runs inline in a web request.
 * The alerts raised by the scheduler itself send inline through
 * {@see self::sendInline()} instead, so a dead or paused Horizon cannot
 * silence them.
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

    /** Share of the app-wide ceiling that turns today's spend into an early warning. */
    private const float TOTAL_CEILING_WARNING_FRACTION = 0.8;

    private const int TOTAL_CEILING_WARNING_COOLDOWN_SECONDS = 3600;

    private const string TOTAL_CEILING_WARNING_CACHE_KEY = 'ai.cost_ceiling.total_warning_cooldown';

    private const float STRAVA_BUDGET_LOW_FRACTION = 0.1;

    private const int STRAVA_BUDGET_WINDOW_SECONDS = 900;

    private const int TELEGRAM_BOT_ALERT_COOLDOWN_SECONDS = 3600;

    private const string TELEGRAM_BOT_ALERT_COOLDOWN_CACHE_KEY = 'telegram.bot_rejected_alert_cooldown';

    /** Keeps the exception digest inside Telegram's 4096-character message limit. */
    public const int EXCEPTION_DIGEST_MAX_LINES = 25;

    /** A failure incident still open this long pages again. */
    private const int FAILURE_REPAGE_SECONDS = 86_400;

    private const int INLINE_TIMEOUT_SECONDS = 5;

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
     * current {@see NarrationGate::pauseReason()} to the last one alerted (stored
     * durably) and pushes only on a change, so an ongoing pause is not re-sent on
     * every hourly self-heal run. A null reason means generation resumed.
     *
     * Returns when the pause began on the one sweep that sees it lift, and null
     * otherwise, including when the lifting pause was the app-wide cost ceiling.
     */
    public function syncPauseState(?string $reason): ?Carbon
    {
        $stored = $this->config->get(AppConfigKey::AiLastPauseReason);
        $previous = is_string($stored) ? $stored : null;

        if ($reason === $previous) {
            return null;
        }

        $startedAt = $this->config->get(AppConfigKey::AiPauseStartedAt);

        $this->config->setMany([
            [AppConfigKey::AiLastPauseReason, $reason],
            [AppConfigKey::AiPauseStartedAt, match (true) {
                $reason === null => null,
                $previous === null => Carbon::now()->toIso8601String(),
                default => $startedAt,
            }],
        ]);
        $this->broadcast($this->pauseMessage($reason));

        if ($reason !== null || $previous === 'cost_ceiling' || ! is_string($startedAt)) {
            return null;
        }

        return Carbon::parse($startedAt);
    }

    /**
     * A scheduled command failed. Wired via `->onFailure()` in routes/console.php
     * so a dead scheduler surfaces as a push instead of silently taking down
     * background processing. The first failure pages; the entry then stays
     * silent until {@see self::schedulerRecovered()}, except for one page per
     * {@see self::FAILURE_REPAGE_SECONDS} while it keeps failing.
     */
    public function schedulerFailed(string $command): void
    {
        $this->openIncident(
            'scheduler.incident.failed:'.$command,
            self::FAILURE_REPAGE_SECONDS,
            "Scheduler failed to run `{$command}`. Check Horizon and the logs.",
            $this->sendInline(...),
        );
    }

    /** A scheduled command succeeded; one line if it was in a failure incident. Wired via `->onSuccess()`. */
    public function schedulerRecovered(string $command): void
    {
        $this->closeIncident(
            'scheduler.incident.failed:'.$command,
            "Scheduler `{$command}` recovered: its latest run succeeded.",
            $this->sendInline(...),
        );
    }

    /**
     * A per-athlete loop swallowed errors for $count athletes; paged once per
     * incident per command like {@see self::schedulerFailed()}.
     */
    public function athletesFailed(string $command, int $count): void
    {
        $athletes = $count === 1 ? '1 athlete' : "{$count} athletes";

        $this->openIncident(
            'scheduler.incident.athletes:'.$command,
            self::FAILURE_REPAGE_SECONDS,
            "Scheduler `{$command}` skipped {$athletes} after errors. Check the logs.",
            $this->sendInline(...),
        );
    }

    /** A per-athlete loop ran with no athlete failing; one line if it was in an incident. */
    public function athletesRecovered(string $command): void
    {
        $this->closeIncident(
            'scheduler.incident.athletes:'.$command,
            "Scheduler `{$command}` recovered: no athlete failed on its latest run.",
            $this->sendInline(...),
        );
    }

    /** An entry missed its schedule, per {@see \App\Console\SchedulerChain::isLate()}; paged once per incident. */
    public function schedulerLate(string $command, ?ScheduledTaskRun $run): void
    {
        $reason = match (true) {
            $run?->hasFailed() === true && $run->last_success_at !== null => 'it has kept failing since its last success on '.$run->last_success_at->format('M j H:i').'.',
            $run?->hasFailed() === true => 'it has kept failing since it was first recorded'.($run->created_at === null ? '' : ' on '.$run->created_at->format('M j H:i')).'.',
            default => 'it has missed its schedule.'.($run?->last_run_at === null ? '' : ' Last run '.$run->last_run_at->format('M j H:i').'.'),
        };

        $this->openIncident(
            'scheduler.incident.late:'.$command,
            null,
            "Scheduler `{$command}` is late: {$reason} Check the scheduler container and the logs.",
            $this->sendInline(...),
        );
    }

    public function schedulerOnTime(string $command): void
    {
        $this->closeIncident(
            'scheduler.incident.late:'.$command,
            "Scheduler `{$command}` is back on time.",
            $this->sendInline(...),
        );
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
        $user = $userId !== null ? (string) $userId : 'unknown';
        $modelLabel = $model ?? 'unknown';

        $this->broadcastOnce(
            self::METERING_ALERT_COOLDOWN_CACHE_KEY,
            self::METERING_ALERT_COOLDOWN_SECONDS,
            "Token usage metering failed ({$exceptionClass}) for user {$user}, kind {$kind}, model {$modelLabel}. The cost ceiling is under-counting spend until this is fixed.",
        );
    }

    /**
     * The app-wide daily spend ceiling has been passed, so every athlete's
     * narration is now served rule-based until midnight. Unlike one athlete
     * exhausting their own slice, this is worth waking the maintainer for.
     */
    public function totalCeilingReached(float $todayCost, float $ceiling, int $athletes): void
    {
        $degraded = $athletes === 1 ? '1 athlete is' : "{$athletes} athletes are";

        $this->broadcastOnce(
            self::TOTAL_CEILING_ALERT_COOLDOWN_CACHE_KEY,
            self::TOTAL_CEILING_ALERT_COOLDOWN_SECONDS,
            sprintf(
                'App-wide AI spend passed the daily ceiling: $%.2f of $%.2f. %s now served rule-based until midnight.',
                $todayCost,
                $ceiling,
                $degraded,
            ),
        );
    }

    /**
     * One athlete has spent their own daily slice, so their narration is served
     * rule-based until midnight while everyone else is unaffected. Ordinary
     * enough not to wake anyone, frequent enough to want the key dated: one push
     * per athlete per day, not one per gated dispatch.
     */
    public function userCeilingReached(int $userId, float $todayCost, float $ceiling): void
    {
        $key = 'ai.cost_ceiling.user_alert:'.Carbon::today()->toDateString().':'.$userId;

        $this->broadcastOnce($key, 86_400, sprintf(
            'Athlete %d passed their daily AI slice: $%.2f of $%.2f. Their narration is served rule-based until midnight.',
            $userId,
            $todayCost,
            $ceiling,
        ));
    }

    /**
     * Today's app-wide spend, offered on every gated dispatch while there is
     * still headroom. The threshold and the cooldown live here so the ceiling
     * check stays one call: a warning at
     * {@see self::TOTAL_CEILING_WARNING_FRACTION} of the ceiling buys the time
     * that {@see self::totalCeilingReached()} no longer has.
     */
    public function totalCeilingApproaching(float $todayCost, float $ceiling): void
    {
        if ($ceiling <= 0.0 || $todayCost < $ceiling * self::TOTAL_CEILING_WARNING_FRACTION) {
            return;
        }

        $this->broadcastOnce(
            self::TOTAL_CEILING_WARNING_CACHE_KEY,
            self::TOTAL_CEILING_WARNING_COOLDOWN_SECONDS,
            sprintf(
                'App-wide AI spend is at $%.2f of the $%.2f daily ceiling (%d%%). Past it every athlete is served rule-based.',
                $todayCost,
                $ceiling,
                (int) round($todayCost / $ceiling * 100),
            ),
        );
    }

    /**
     * The shared Strava read budget for the current 15-minute window is nearly
     * spent. The limit is per client app rather than per athlete, so both the
     * threshold and the dedupe key are global: one push per window covers every
     * athlete's sync, and the next window may warn again.
     */
    public function stravaBudgetLow(int $remaining, int $budget): void
    {
        if ($remaining >= $budget * self::STRAVA_BUDGET_LOW_FRACTION) {
            return;
        }

        $now = Carbon::now();
        $window = $now->format('Y-m-d-H').':'.intdiv($now->minute, 15);

        $this->broadcastOnce('strava.rate_limit.budget_alert:'.$window, self::STRAVA_BUDGET_WINDOW_SECONDS, sprintf(
            'Strava reads are nearly spent: %d of %d left in this 15-minute window. Background hydration backs off first.',
            $remaining,
            $budget,
        ));
    }

    /**
     * Telegram rejected the bot itself (401 bad token, 404 unknown method), so
     * no athlete can be reached until the config is fixed. One push per hour.
     */
    public function telegramBotRejected(int $status): void
    {
        $this->broadcastOnce(
            self::TELEGRAM_BOT_ALERT_COOLDOWN_CACHE_KEY,
            self::TELEGRAM_BOT_ALERT_COOLDOWN_SECONDS,
            "Telegram rejected the bot with status {$status}. Check TELEGRAM_BOT_TOKEN; no athlete links were revoked.",
        );
    }

    /**
     * The evening spend digest: what today cost app-wide, what is left against
     * each ceiling, a line per athlete who spent anything, and yesterday's
     * complete spend, including the hours after yesterday's digest. Sent once by
     * {@see \App\Console\Commands\AI\SpendDigestCommand}, so no dedupe of its own.
     *
     * @param  list<array{userId: int, calls: int, tokens: int, cost: float}>  $rows
     */
    public function spendDigest(array $rows, float $todayCost, ?float $perUserCeiling, ?float $totalCeiling, float $yesterdayCost): void
    {
        $calls = array_sum(array_column($rows, 'calls'));
        $tokens = array_sum(array_column($rows, 'tokens'));

        $against = $totalCeiling === null ? '' : sprintf(' of $%.2f', $totalCeiling);

        $headline = sprintf('AI spend today: $%.2f%s%s. ', $todayCost, $against, $this->headroom($todayCost, $totalCeiling))
            .($rows === []
                ? 'No athlete spent anything today.'
                : sprintf('%d calls, %s tokens.', $calls, number_format($tokens)));

        $lines = array_map(fn (array $row): string => sprintf(
            '- athlete %d: %d calls, %s tokens, $%.2f%s',
            $row['userId'],
            $row['calls'],
            number_format($row['tokens']),
            $row['cost'],
            $this->headroom($row['cost'], $perUserCeiling),
        ), $rows);

        $this->broadcast(implode("\n", [$headline, ...$lines, sprintf('AI spend yesterday, final: $%.2f.', $yesterdayCost)]));
    }

    /**
     * The daily list of never-seen exception fingerprints, sent once by
     * {@see \App\Console\Commands\ExceptionDigestCommand} and only when the list
     * is non-empty. Each entry is a location, never a message, so no athlete
     * data reaches the chat.
     *
     * @param  list<array{label: string, first_seen: string, count: int}>  $entries
     */
    public function exceptionDigest(array $entries): void
    {
        $total = count($entries);
        $headline = $total === 1 ? '1 new exception since the last digest.' : "{$total} new exceptions since the last digest.";

        $lines = array_map(fn (array $entry): string => sprintf(
            '- %s, first seen %s, %s',
            $entry['label'],
            Carbon::parse($entry['first_seen'])->format('M j H:i'),
            $entry['count'] === 1 ? 'once' : "{$entry['count']} times",
        ), array_slice($entries, 0, self::EXCEPTION_DIGEST_MAX_LINES));

        if ($total > self::EXCEPTION_DIGEST_MAX_LINES) {
            $lines[] = '- and '.($total - self::EXCEPTION_DIGEST_MAX_LINES).' more';
        }

        $this->broadcast(implode("\n", [$headline, ...$lines]));
    }

    /**
     * Monday entries that still have not succeeded by the 06:00 check. They keep
     * retrying hourly, so one push per ISO week is enough.
     *
     * @param  list<string>  $entries
     */
    public function mondayEntriesOverdue(array $entries): void
    {
        $this->broadcastOnce(
            'scheduler.monday_overdue:'.Carbon::now()->isoFormat('GGGG-[W]WW'),
            7 * 86_400,
            'Monday scheduler entries have not succeeded by 06:00: '.implode(', ', $entries).'. They keep retrying hourly today; check Horizon and the logs.',
        );
    }

    /**
     * A queued job's final attempt failed; paged like {@see self::schedulerFailed()}
     * but queued, since the job already runs on a worker.
     */
    public function jobFailed(string $job): void
    {
        $this->openIncident(
            'scheduler.incident.job_failed:'.$job,
            self::FAILURE_REPAGE_SECONDS,
            "Queued job `{$job}` failed. Check Horizon and the logs.",
            $this->broadcast(...),
        );
    }

    public function jobRecovered(string $job): void
    {
        $this->closeIncident(
            'scheduler.incident.job_failed:'.$job,
            "Queued job `{$job}` recovered: its latest run succeeded.",
            $this->broadcast(...),
        );
    }

    /**
     * How many runs a backfill command still leaves without $field between
     * {@see ActivityDetail::PERSISTENT_GAP_HOURS} hours and
     * {@see ActivityDetail::PERSISTENT_GAP_MAX_DAYS} days after ingest. Above
     * zero opens one incident per command; zero closes it with one line.
     */
    public function persistentGap(string $command, string $field, int $count): void
    {
        $key = 'scheduler.incident.gap:'.$command;

        if ($count === 0) {
            $this->closeIncident($key, "Scheduler `{$command}`: every run has its {$field} again.", $this->sendInline(...));

            return;
        }

        $runs = $count === 1 ? '1 run is' : "{$count} runs are";

        $this->openIncident(
            $key,
            null,
            sprintf('Scheduler `%s`: %s still missing %s %d h after ingest. Check the logs.', $command, $runs, $field, ActivityDetail::PERSISTENT_GAP_HOURS),
            $this->sendInline(...),
        );
    }

    /** " ($4.58 left)", or nothing at all when that ceiling is unset. */
    private function headroom(float $spent, ?float $ceiling): string
    {
        return $ceiling === null
            ? ''
            : sprintf(' ($%.2f left)', max(0.0, $ceiling - $spent));
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

    /**
     * Broadcasts $message the first time $key is claimed within $ttl seconds, and
     * no-ops on every repeat until the window lapses. A cache error sends anyway.
     */
    private function broadcastOnce(string $key, int $ttl, string $message): void
    {
        try {
            if (! Cache::add($key, true, $ttl)) {
                return;
            }
        } catch (Throwable $e) {
            $this->logKeyFailure($key, $e);
        }

        $this->broadcast($message);
    }

    /**
     * Sends $message through $send when the incident at $key opens, and again
     * once $repageSeconds have passed while it stays open (never, when null).
     * The key lives on the durable store; a cache error sends anyway.
     *
     * @param  callable(string): void  $send
     */
    private function openIncident(string $key, ?int $repageSeconds, string $message, callable $send): void
    {
        $now = Carbon::now()->getTimestamp();

        try {
            $store = Cache::store('durable');
            $pagedAt = $store->get($key);

            if (is_numeric($pagedAt) && ($repageSeconds === null || $now - (int) $pagedAt < $repageSeconds)) {
                return;
            }

            $store->forever($key, $now);
        } catch (Throwable $e) {
            $this->logKeyFailure($key, $e);
        }

        $send($message);
    }

    /**
     * Sends $message through $send when the incident at $key was open, and
     * closes it. A cache error stays silent, since every on-time sweep and
     * every success calls this.
     *
     * @param  callable(string): void  $send
     */
    private function closeIncident(string $key, string $message, callable $send): void
    {
        try {
            if (Cache::store('durable')->pull($key) === null) {
                return;
            }
        } catch (Throwable $e) {
            $this->logKeyFailure($key, $e);

            return;
        }

        $send($message);
    }

    private function logKeyFailure(string $key, Throwable $e): void
    {
        Log::warning('maintainer_alert.key_failed', [
            'key' => $key,
            'reason' => $e->getMessage(),
        ]);
    }

    /**
     * Send $message to every admin's active Telegram chat right now, each send
     * capped at {@see self::INLINE_TIMEOUT_SECONDS}; no-op when unconfigured.
     * Only for alerts raised by the scheduler process, never a web request.
     */
    private function sendInline(string $message): void
    {
        if (blank(config('services.telegram.bot_token'))) {
            return;
        }

        $this->deliver(fn (int $chatId) => $this->telegram->sendMessage($chatId, $message, self::INLINE_TIMEOUT_SECONDS));
    }

    /**
     * Queue $message for every admin's active Telegram chat; no-op when
     * unconfigured. Dispatches rather than sending, so the caller — a web
     * request resolving a shared prop, a console command, or another queued
     * job — never makes an outbound Telegram call itself.
     */
    private function broadcast(string $message): void
    {
        if (blank(config('services.telegram.bot_token'))) {
            return;
        }

        SendMaintainerAlertJob::dispatch($message);
    }

    /**
     * The actual send, run from {@see \App\Jobs\AI\SendMaintainerAlertJob}.
     * Public so the job can call it; every dedupe/throttle decision happens
     * before {@see self::broadcast()} dispatches the job, so this method
     * itself never gates — it only delivers.
     */
    public function sendToAdmins(string $message): void
    {
        $this->deliver(fn (int $chatId) => $this->telegram->sendMessage($chatId, $message));
    }

    /** @param  callable(int): void  $send */
    private function deliver(callable $send): void
    {
        $connections = TelegramConnection::query()
            ->active()
            ->whereHas('user', fn (Builder $query) => $query->where('is_admin', true))
            ->get();

        foreach ($connections as $connection) {
            try {
                $send($connection->chat_id);
            } catch (Throwable $e) {
                Log::warning('maintainer_alert.send_failed', [
                    'chat_id' => $connection->chat_id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
