<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Global circuit breaker for Azure OpenAI *configuration/auth* failures: a wrong
 * API key (401/403) or a wrong base URL/host (persistent DNS/connection failure).
 * Trips after a short streak of these so a fat-fingered env stops burning the
 * per-row retry budget and spamming failed_jobs; auto half-opens after a cooldown
 * to probe recovery, so fixing the env auto-resumes for free. State is durable
 * (app_config) so it survives restarts and is visible across containers.
 *
 * Only config/auth failures count. Transient upstream errors (429 / 5xx) route to
 * the queue's own retry/backoff and never touch this breaker. Mirrors
 * {@see \App\Services\Strava\StravaCircuitBreaker} for the AI narration pipeline.
 */
class AzureConfigCircuitBreaker
{
    public const string STATE_CLOSED = 'closed';

    public const string STATE_OPEN = 'open';

    public const string STATE_HALF_OPEN = 'half_open';

    private const string LOCK_KEY = 'ai-config-breaker';

    private const int LOCK_SECONDS = 5;

    public function __construct(private readonly AppConfig $config)
    {
    }

    /**
     * Whether an Azure call may proceed. Closed/half-open allow it; open blocks
     * until the cooldown elapses, then flips to half-open to allow one probe.
     */
    public function allowsRequest(): bool
    {
        if ($this->state() !== self::STATE_OPEN) {
            return true;
        }

        if (! $this->withinCooldown()) {
            // Cooldown elapsed: allow a single probe through under half-open.
            $this->withLock(function (): void {
                $this->forgetState();
                if ($this->state() === self::STATE_OPEN) {
                    $this->config->set(AppConfigKey::AiConfigBreakerState, self::STATE_HALF_OPEN);
                }
            });

            return true;
        }

        return false;
    }

    /**
     * Read-only view of whether the breaker is currently blocking requests, for
     * the /pulse dashboard's pause-reason line. Unlike {@see self::allowsRequest()}
     * it never transitions to half-open, so reporting doesn't consume the probe.
     */
    public function isTripped(): bool
    {
        return $this->state() === self::STATE_OPEN && $this->withinCooldown();
    }

    public function recordSuccess(): void
    {
        // Fast path: nothing to clear, so no lock or write on a healthy call.
        if ($this->state() === self::STATE_CLOSED
            && $this->config->integer(AppConfigKey::AiConfigBreakerFailures) === 0) {
            return;
        }

        $this->reset();
    }

    public function recordFailure(): void
    {
        $this->withLock(function (): void {
            $this->forgetState();

            // A probe failed under half-open: re-open and restart the cooldown.
            if ($this->state() === self::STATE_HALF_OPEN) {
                $this->open();

                return;
            }

            $failures = $this->config->integer(AppConfigKey::AiConfigBreakerFailures) + 1;
            $this->config->set(AppConfigKey::AiConfigBreakerFailures, $failures);

            if ($failures >= $this->config->integer(AppConfigKey::AiConfigBreakerThreshold)) {
                $this->open();
            }
        });
    }

    public function reset(): void
    {
        $this->withLock(fn () => $this->close());
    }

    public function state(): string
    {
        return (string) $this->config->get(AppConfigKey::AiConfigBreakerState);
    }

    /**
     * @return array{state: string, failures: int, opened_at: string|null}
     */
    public function snapshot(): array
    {
        $openedAt = $this->config->get(AppConfigKey::AiConfigBreakerOpenedAt);

        return [
            'state' => $this->state(),
            'failures' => $this->config->integer(AppConfigKey::AiConfigBreakerFailures),
            'opened_at' => is_string($openedAt) ? $openedAt : null,
        ];
    }

    /**
     * Whether "now" is still inside the open cooldown window. A missing opened_at
     * (corrupt/partial state write) reads as elapsed, so the breaker fails open.
     */
    private function withinCooldown(): bool
    {
        $openedAt = $this->config->get(AppConfigKey::AiConfigBreakerOpenedAt);
        if (! is_string($openedAt)) {
            return false;
        }

        $cooldown = $this->config->integer(AppConfigKey::AiConfigBreakerCooldownSeconds);

        return Carbon::now()->lessThanOrEqualTo(Carbon::parse($openedAt)->addSeconds($cooldown));
    }

    private function open(): void
    {
        $this->config->setMany([
            [AppConfigKey::AiConfigBreakerState, self::STATE_OPEN],
            [AppConfigKey::AiConfigBreakerOpenedAt, Carbon::now()->toIso8601String()],
        ]);
    }

    private function close(): void
    {
        $this->config->setMany([
            [AppConfigKey::AiConfigBreakerState, self::STATE_CLOSED],
            [AppConfigKey::AiConfigBreakerFailures, 0],
            [AppConfigKey::AiConfigBreakerOpenedAt, null],
        ]);
    }

    private function forgetState(): void
    {
        $this->config->forget(AppConfigKey::AiConfigBreakerState);
        $this->config->forget(AppConfigKey::AiConfigBreakerFailures);
        $this->config->forget(AppConfigKey::AiConfigBreakerOpenedAt);
    }

    private function withLock(Closure $callback): void
    {
        Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $callback);
    }
}
