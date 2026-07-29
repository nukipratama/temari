<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

abstract class AnalyzeBaseJob implements ShouldQueue
{
    use Queueable;

    /**
     * Narration runs on its own queue under its own Horizon supervisor: a
     * tool-calling agent takes several Azure round trips, which does not fit
     * the timeout the rest of the queue lives by, and holding a shared worker
     * that long would stall Strava ingest behind it.
     */
    public const string QUEUE = 'ai';

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Upper bound on a `Retry-After` release delay (seconds), so an oversized
     * upstream value cannot park a row for hours.
     */
    private const int MAX_RETRY_AFTER_SECONDS = 600;

    /** Recorded on a row halted by {@see self::haltForSpentRetryBudget()}. */
    private const string SPENT_BUDGET_ERROR = 'Retry budget exhausted before this attempt could run.';

    /**
     * Settle a generation failure, given callbacks that mark the affected
     * row(s) failed or re-queued.
     *
     * Any `TransientUpstreamException` (429 / 5xx / timeout) is retryable while
     * both a `$tries` slot and a row retry budget remain, whether or not it
     * carries a `Retry-After`: re-queue the row(s) and release the job. The
     * release delay is the upstream `Retry-After` when present, otherwise the
     * configured backoff, capped at {@see self::MAX_RETRY_AFTER_SECONDS}. A
     * re-queued row is neither re-dispatchable nor shown as "Coba lagi", so a
     * manual retry cannot race a second LLM call during the wait.
     *
     * Every other outcome ends this attempt failed. `UnavailableException` is
     * terminal (bad schema / malformed JSON / permanent upstream error) and is
     * swallowed so the worker does not retry; anything else (a transient error
     * with no slot left, or a genuine bug) is rethrown so the queue records it
     * in `failed_jobs`.
     *
     * @param  iterable<Analysis>  $rows
     */
    protected function settleFailure(Throwable $e, iterable $rows, callable $markFailed, callable $markRequeued): void
    {
        if ($e instanceof TransientUpstreamException
            && $this->retryBudgetRemains($rows)
            && $this->attempts() < $this->tries) {
            $markRequeued();
            $this->release(min($e->retryAfterSeconds ?? $this->defaultBackoffSeconds(), self::MAX_RETRY_AFTER_SECONDS));

            return;
        }

        $markFailed();

        if (! $e instanceof UnavailableException) {
            throw $e;
        }
    }

    /**
     * First configured `$backoff` step, used as the release delay when a
     * transient failure carries no `Retry-After` hint.
     */
    private function defaultBackoffSeconds(): int
    {
        return $this->backoff[0] ?? 0;
    }

    /**
     * Refuse to bill a run the row's retry budget can no longer pay for, and
     * settle it so it dead-letters. Returns true to tell handle() to stop.
     *
     * `attempts` bumps once per real run (markProcessing), so it is the single
     * budget the queue's own `$tries` retries and ai:self-heal's re-dispatches
     * both draw from, and {@see Analysis::MAX_SELF_HEAL_ATTEMPTS} bounds their
     * sum rather than each half separately. Every dispatch leaves its row
     * Queued, so a row arriving Failed or Processing is a queue-driven re-entry
     * (a rethrown exception being retried, or a run whose worker died
     * mid-flight) — a manual re-trigger still gets its run. A halted row that is
     * not already Failed is marked so, rather than resting in a state no sweep
     * can see.
     *
     * @param  iterable<Analysis>  $rows
     */
    protected function haltForSpentRetryBudget(AnalysisService $service, iterable $rows): bool
    {
        $spent = [];

        foreach ($rows as $row) {
            if (! self::retryBudgetSpent($row)) {
                return false;
            }

            $spent[] = $row;
        }

        if ($spent === []) {
            return false;
        }

        foreach ($spent as $row) {
            if ($row->status !== AnalysisStatus::Failed) {
                $service->markFailed($row, self::SPENT_BUDGET_ERROR);
            }
        }

        return true;
    }

    private static function retryBudgetSpent(Analysis $row): bool
    {
        return $row->status !== AnalysisStatus::Queued
            && $row->attempts >= Analysis::MAX_SELF_HEAL_ATTEMPTS;
    }

    /**
     * Whether any row of this run may still spend a real LLM attempt.
     *
     * @param  iterable<Analysis>  $rows
     */
    private function retryBudgetRemains(iterable $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->attempts < Analysis::MAX_SELF_HEAL_ATTEMPTS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuse to bill while generation is paused (cost ceiling / AI off / Azure
     * unset). The cap is otherwise enforced only at dispatch time, so a job
     * dispatched just before the ceiling tripped would still call the LLM; this
     * closes that window. Reverts the given rows to Pending (never Failed, and
     * before markProcessing, so no `attempts` burn) and returns true to tell
     * handle() to stop; ai:self-heal re-dispatches once generation resumes.
     *
     * @param  iterable<Analysis>  $rows
     */
    protected function haltForPausedGeneration(AnalysisService $service, iterable $rows): bool
    {
        if (! $service->generationPaused()) {
            return false;
        }

        foreach ($rows as $row) {
            if ($row->status !== AnalysisStatus::Pending) {
                $service->revertToPending($row);
            }
        }

        return true;
    }
}
