<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Global circuit breaker for the Strava API. Trips after a streak of transient
 * failures (5xx / timeouts) so a sustained outage stops being hammered every
 * cycle; auto half-opens after a cooldown to probe recovery. State is durable
 * (app_config) so it survives restarts and is visible across containers.
 *
 * Only transient failures count — 401 routes to connection-revoke and 429 to the
 * rate limiter, neither touches the breaker.
 */
class StravaCircuitBreaker
{
    public const string STATE_CLOSED = 'closed';

    public const string STATE_OPEN = 'open';

    public const string STATE_HALF_OPEN = 'half_open';

    private const string LOCK_KEY = 'strava-breaker';

    private const int LOCK_SECONDS = 5;

    public function __construct(private readonly AppConfig $config)
    {
    }

    /**
     * Whether a Strava call may proceed. Closed/half-open allow it; open blocks
     * until the cooldown elapses, then flips to half-open to allow one probe.
     */
    public function allowsRequest(): bool
    {
        if ($this->state() !== self::STATE_OPEN) {
            return true;
        }

        $openedAt = $this->config->get(AppConfigKey::StravaBreakerOpenedAt);
        if (! is_string($openedAt)) {
            return true;
        }

        $cooldown = $this->config->integer(AppConfigKey::StravaBreakerCooldownSeconds);
        if (Carbon::now()->lessThanOrEqualTo(Carbon::parse($openedAt)->addSeconds($cooldown))) {
            return false;
        }

        // Cooldown elapsed: allow a single probe through under half-open.
        $this->withLock(function (): void {
            $this->forgetState();
            if ($this->state() === self::STATE_OPEN) {
                $this->config->set(AppConfigKey::StravaBreakerState, self::STATE_HALF_OPEN);
            }
        });

        return true;
    }

    public function recordSuccess(): void
    {
        // Fast path: nothing to clear, so no lock or write on a healthy call.
        if ($this->state() === self::STATE_CLOSED
            && $this->config->integer(AppConfigKey::StravaBreakerFailures) === 0) {
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

            $failures = $this->config->integer(AppConfigKey::StravaBreakerFailures) + 1;
            $this->config->set(AppConfigKey::StravaBreakerFailures, $failures);

            if ($failures >= $this->config->integer(AppConfigKey::StravaBreakerThreshold)) {
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
        return (string) $this->config->get(AppConfigKey::StravaBreakerState);
    }

    /**
     * @return array{state: string, failures: int, opened_at: string|null}
     */
    public function snapshot(): array
    {
        $openedAt = $this->config->get(AppConfigKey::StravaBreakerOpenedAt);

        return [
            'state' => $this->state(),
            'failures' => $this->config->integer(AppConfigKey::StravaBreakerFailures),
            'opened_at' => is_string($openedAt) ? $openedAt : null,
        ];
    }

    private function open(): void
    {
        $this->config->setMany([
            [AppConfigKey::StravaBreakerState, self::STATE_OPEN],
            [AppConfigKey::StravaBreakerOpenedAt, Carbon::now()->toIso8601String()],
        ]);
    }

    private function close(): void
    {
        $this->config->setMany([
            [AppConfigKey::StravaBreakerState, self::STATE_CLOSED],
            [AppConfigKey::StravaBreakerFailures, 0],
            [AppConfigKey::StravaBreakerOpenedAt, null],
        ]);
    }

    private function forgetState(): void
    {
        $this->config->forget(AppConfigKey::StravaBreakerState);
        $this->config->forget(AppConfigKey::StravaBreakerFailures);
        $this->config->forget(AppConfigKey::StravaBreakerOpenedAt);
    }

    private function withLock(Closure $callback): void
    {
        Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $callback);
    }
}
