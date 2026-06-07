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
     * Decide what happens after a generation failure.
     *
     * `UnavailableException` is terminal (bad schema / malformed JSON /
     * permanent upstream error): swallow it so the row stays marked failed and
     * the worker does not retry. `TransientUpstreamException` (429 / 5xx /
     * timeout) is retryable: when Azure handed us a `Retry-After`, release the
     * job with that delay (this still consumes a `$tries` slot, so the attempt
     * accounting and eventual `failed()` hook are unchanged); otherwise rethrow
     * and let the static `$backoff` drive the retry. Anything else (a genuine
     * bug) is rethrown unchanged.
     */
    protected function rethrowIfUnexpected(Throwable $e): void
    {
        if ($e instanceof UnavailableException) {
            return;
        }

        if ($e instanceof TransientUpstreamException && $e->retryAfterSeconds !== null) {
            $this->release($e->retryAfterSeconds);

            return;
        }

        throw $e;
    }
}
