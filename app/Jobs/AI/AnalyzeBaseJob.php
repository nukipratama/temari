<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

abstract class AnalyzeBaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * Upper bound on a `Retry-After` release delay (seconds), so an oversized
     * upstream value cannot park a row for hours.
     */
    private const int MAX_RETRY_AFTER_SECONDS = 600;

    /**
     * Settle a generation failure, given callbacks that mark the affected
     * row(s) failed or re-queued.
     *
     * Any `TransientUpstreamException` (429 / 5xx / timeout) is retryable while
     * a `$tries` slot remains, whether or not it carries a `Retry-After`:
     * re-queue the row(s) and release the job. The release delay is the
     * upstream `Retry-After` when present, otherwise the configured backoff,
     * capped at {@see self::MAX_RETRY_AFTER_SECONDS}. A re-queued row is neither
     * re-dispatchable nor shown as "Coba lagi", so a manual retry cannot race a
     * second LLM call during the wait.
     *
     * Every other outcome ends this attempt failed. `UnavailableException` is
     * terminal (bad schema / malformed JSON / permanent upstream error) and is
     * swallowed so the worker does not retry; anything else (a transient error
     * with no `$tries` slot left, or a genuine bug) is rethrown so the queue
     * records it in `failed_jobs`.
     */
    protected function settleFailure(Throwable $e, callable $markFailed, callable $markRequeued): void
    {
        if ($e instanceof TransientUpstreamException
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
}
