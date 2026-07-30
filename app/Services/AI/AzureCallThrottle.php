<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\TransientUpstreamException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Local ceiling on outbound Azure OpenAI calls, checked before every request
 * so concurrent workers self-throttle instead of firehosing Azure and eating
 * 429s. Ordinary contention (a couple of workers overlapping) clears in a
 * few seconds and is worth blocking for rather than spending a job retry-
 * budget attempt on it; sustained oversubscription past the block cap throws
 * instead of blocking a worker indefinitely — the caller's existing
 * {@see \App\Jobs\AI\AnalyzeBaseJob::settleFailure()} retry-budget/backoff
 * path takes it from there.
 *
 * The sleep step is an injectable callable (defaulting to the real `sleep()`)
 * so tests can fake time passing instead of actually waiting.
 */
class AzureCallThrottle
{
    private const string KEY = 'azure-openai-calls';

    /**
     * At the default 15/min, a slot opens roughly every 4s, so this
     * comfortably covers a couple of workers briefly overlapping without
     * tying up a worker anywhere near the queue's job timeout.
     */
    private const int BLOCK_CAP_SECONDS = 30;

    /** @var callable(int): void */
    private $sleeper;

    /** @param  (callable(int): void)|null  $sleeper */
    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function block(): void
    {
        $maxPerMinute = max(1, (int) config('ai.azure_calls_per_minute'));
        $waited = 0;

        while (! RateLimiter::attempt(self::KEY, $maxPerMinute, fn (): bool => true, 60)) {
            $availableIn = RateLimiter::availableIn(self::KEY);

            if ($waited >= self::BLOCK_CAP_SECONDS) {
                throw new TransientUpstreamException(
                    'Locally throttled before calling Azure OpenAI.',
                    retryAfterSeconds: $availableIn,
                );
            }

            $sleepSeconds = max(1, min($availableIn, self::BLOCK_CAP_SECONDS - $waited));
            ($this->sleeper)($sleepSeconds);
            $waited += $sleepSeconds;
        }
    }
}
