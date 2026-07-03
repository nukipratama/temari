<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A single-shot 15-minute cooldown keyed by an arbitrary string, backed by the
 * Redis RateLimiter (cache connection, isolated from the queue). One `start()`
 * makes the key active for the whole window; `remaining()` reports the countdown
 * so a caller can surface a "wait Xm" disabled state. Used to throttle manual AI
 * re-triggers and manual Telegram sends without re-firing an LLM call or a push.
 */
final readonly class Cooldown
{
    public const int WINDOW_SECONDS = 900;

    public function __construct(private string $key) {}

    public function isActive(): bool
    {
        return RateLimiter::tooManyAttempts($this->key, 1);
    }

    /**
     * Seconds left in the window, or null when not cooling.
     */
    public function remaining(): ?int
    {
        return $this->isActive() ? RateLimiter::availableIn($this->key) : null;
    }

    public function start(): void
    {
        RateLimiter::hit($this->key, self::WINDOW_SECONDS);
    }

    public static function telegramKey(int $analysisId): string
    {
        return "telegram-send:{$analysisId}";
    }
}
