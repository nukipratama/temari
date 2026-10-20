<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\TransientUpstreamException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Local ceiling on outbound Azure OpenAI calls, checked before every request so concurrent workers
 * self-throttle instead of eating 429s. Contention blocks briefly; sustained oversubscription past
 * the block cap throws, and {@see \App\Jobs\AI\AnalyzeBaseJob::settleFailure()} takes it from
 * there.
 *
 * The sleep step is an injectable callable so tests can fake time passing.
 */
class AzureCallThrottle
{
    private const string KEY = 'azure-openai-calls';

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
        $maxPerMinute = max(1, config()->integer('ai.azure_calls_per_minute'));
        $blockCapSeconds = max(1, config()->integer('ai.azure_block_cap_seconds'));
        $waited = 0;

        while (! RateLimiter::attempt(self::KEY, $maxPerMinute, fn (): bool => true, 60)) {
            $availableIn = RateLimiter::availableIn(self::KEY);

            if ($waited >= $blockCapSeconds) {
                throw new TransientUpstreamException(
                    'Locally throttled before calling Azure OpenAI.',
                    retryAfterSeconds: $availableIn,
                );
            }

            $sleepSeconds = max(1, min($availableIn, $blockCapSeconds - $waited));
            ($this->sleeper)($sleepSeconds);
            $waited += $sleepSeconds;
        }
    }
}
