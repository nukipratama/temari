<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A single-shot 15-minute cooldown keyed by an arbitrary string, backed by the
 * Redis RateLimiter (cache connection, isolated from the queue). One `start()`
 * makes the key active for the whole window; `remaining()` reports the countdown
 * so a caller can surface a "wait Xm" disabled state. Used to throttle manual AI
 * re-triggers and manual notification sends without re-firing an LLM call or a push.
 */
final readonly class Cooldown
{
    public const int WINDOW_SECONDS = 900;

    public function __construct(private string $key)
    {
    }

    public function isActive(): bool
    {
        return RateLimiter::tooManyAttempts($this->key, 1);
    }

    /**
     * Starts the window and returns true, unless it was already active (then
     * it's left untouched and this returns false). Prefer this over a separate
     * {@see self::isActive()} + {@see self::start()} pair, which leaves a gap
     * for two concurrent callers to both see it inactive and both proceed.
     */
    public function attempt(): bool
    {
        return RateLimiter::attempt($this->key, 1, fn (): bool => true, self::WINDOW_SECONDS);
    }

    /**
     * Seconds left in the window, or null when not cooling. Reads
     * `availableIn()` directly (it's 0, not negative, once the window has
     * elapsed or was never started) rather than checking {@see self::isActive()}
     * first, so a single Redis round trip covers both the check and the value.
     */
    public function remaining(): ?int
    {
        $seconds = RateLimiter::availableIn($this->key);

        return $seconds > 0 ? $seconds : null;
    }

    public function start(): void
    {
        RateLimiter::hit($this->key, self::WINDOW_SECONDS);
    }

    public static function notificationKey(int $analysisId): string
    {
        return "notification-send:{$analysisId}";
    }
}
