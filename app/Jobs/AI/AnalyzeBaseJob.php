<?php

declare(strict_types=1);

namespace App\Jobs\AI;

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

    protected function modelVersion(): ?string
    {
        $deployment = config('azure_openai.deployment');

        return is_string($deployment) && $deployment !== '' ? $deployment : null;
    }

    /**
     * Let `UnavailableException` (transient Azure failures) flow through silently
     * after marking the row(s) failed; rethrow everything else so the queue
     * worker applies the retry policy.
     */
    protected function rethrowIfUnexpected(Throwable $e): void
    {
        if (! $e instanceof UnavailableException) {
            throw $e;
        }
    }
}
