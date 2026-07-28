<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A single-shot cooldown keyed by an arbitrary string, backed by the Redis
 * RateLimiter (cache connection, isolated from the queue). One `start()` makes
 * the key active for the whole window; `remaining()` reports the countdown so a
 * caller can surface a "wait Xm" disabled state.
 *
 * The window is per-instance because the three uses guard different things.
 * Re-narrating a block spends money, so it holds longest. Re-sending an
 * existing narration spends nothing and only has to spare the recipient a
 * duplicate buzz. The test send protects nobody at all — it exists to prove a
 * channel works, and is pressed exactly when someone is setting one up and
 * iterating, so a long lock turns a 30-second check into a 15-minute one.
 */
final readonly class Cooldown
{
    /**
     * Default window: the per-block AI re-narration guard ("Baca ulang"),
     * started from AnalysisService::markDone(). This one is a **cost** guard —
     * every re-fire is a paid LLM call — so it stays long. See the
     * per-block-manual-retry decision note before shortening it.
     */
    public const int WINDOW_SECONDS = 900;

    /**
     * Manual re-send of an already-generated narration. Costs nothing to run,
     * so it only has to stop the recipient being buzzed twice about the same
     * thing.
     */
    public const int NOTIFICATION_WINDOW_SECONDS = 300;

    /** "Kirim notifikasi tes" — short, because it is a setup-time debug tool. */
    public const int TEST_WINDOW_SECONDS = 60;

    public function __construct(private string $key, private int $window = self::WINDOW_SECONDS)
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
        return RateLimiter::attempt($this->key, 1, fn (): bool => true, $this->window);
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
        RateLimiter::hit($this->key, $this->window);
    }

    /**
     * {@see self::remaining()} for many keys in one cache round trip (an MGET on
     * Redis) instead of one `availableIn()` per key. List payloads resolve a
     * countdown per row, so the per-key form turns one page into hundreds of
     * sequential round trips.
     *
     * Reads the same `:timer` entries from the same limiter store `availableIn()`
     * reads, so the values are identical to calling it key by key. CooldownTest
     * pins that equivalence, which is what would catch the framework changing
     * this layout underneath us.
     *
     * @param  array<int, string>  $keys
     * @return array<string, int|null>  Keyed by the original key.
     */
    public static function remainingMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $timerKeys = [];
        foreach ($keys as $key) {
            $timerKeys[$key] = RateLimiter::cleanRateLimiterKey($key).':timer';
        }

        $availableAt = Cache::driver(config('cache.limiter'))->many(array_values($timerKeys));
        $now = Carbon::now()->getTimestamp();

        $remaining = [];
        foreach ($timerKeys as $key => $timerKey) {
            $value = $availableAt[$timerKey] ?? null;
            $seconds = is_numeric($value) ? (int) $value - $now : 0;
            $remaining[$key] = $seconds > 0 ? $seconds : null;
        }

        return $remaining;
    }

    public static function notificationKey(int $analysisId): string
    {
        return "notification-send:{$analysisId}";
    }

    /**
     * Keyed by user, not by analysis: a test send has no subject, so the only
     * thing worth rate-limiting is the person pressing the button.
     */
    public static function testNotificationKey(int $userId): string
    {
        return "notification-test:{$userId}";
    }
}
