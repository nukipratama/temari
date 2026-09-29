<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Day-scoped record of the daily spend ceiling tripping: when it first tripped
 * and how much was served rule-based because of it, counting both narration
 * blocks and run-question answers. Keyed by date and cache-backed rather than
 * migrated, because it answers one operator question on /devtools/narration about the
 * current day, and the spend history it would duplicate already lives in
 * ai_token_usages. Each fill is also tallied by kind and athlete, so the day can
 * be broken down beyond the bare total.
 */
class CostCeilingLedger
{
    private const int TTL_SECONDS = 172_800;

    public function recordTrip(): void
    {
        Cache::add($this->key('tripped_at'), Carbon::now()->toIso8601String(), self::TTL_SECONDS);
    }

    public function recordDegradedFill(string $kind, ?int $userId): void
    {
        Cache::add($this->key('fills'), 0, self::TTL_SECONDS);
        Cache::increment($this->key('fills'));

        Cache::lock($this->key('breakdown_lock'), 5)->block(2, function () use ($kind, $userId): void {
            $breakdown = $this->breakdown();
            $slot = $kind.':'.($userId ?? '');
            $breakdown[$slot] = ['kind' => $kind, 'userId' => $userId, 'count' => ($breakdown[$slot]['count'] ?? 0) + 1];
            Cache::put($this->key('breakdown'), $breakdown, self::TTL_SECONDS);
        });
    }

    /**
     * @return array{trippedAt: string|null, degradedFills: int, degradedBreakdown: list<array{kind: string, userId: int|null, count: int}>}
     */
    public function today(): array
    {
        $trippedAt = Cache::get($this->key('tripped_at'));

        return [
            'trippedAt' => is_string($trippedAt) ? $trippedAt : null,
            'degradedFills' => (int) Cache::get($this->key('fills'), 0),
            'degradedBreakdown' => array_values($this->breakdown()),
        ];
    }

    /** @return array<string, array{kind: string, userId: int|null, count: int}> */
    private function breakdown(): array
    {
        $stored = Cache::get($this->key('breakdown'), []);

        return is_array($stored) ? $stored : [];
    }

    private function key(string $suffix): string
    {
        return 'ai:cost-ceiling:'.Carbon::today()->toDateString().':'.$suffix;
    }
}
