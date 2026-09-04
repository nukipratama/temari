<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Day-scoped record of the daily spend ceiling tripping: when it first tripped
 * and how much was served rule-based because of it, counting both narration
 * blocks and run-question answers. Keyed by date and cache-backed rather than
 * migrated, because it answers one operator question on /devtools/ai-usage about the
 * current day, and the spend history it would duplicate already lives in
 * ai_token_usages.
 */
class CostCeilingLedger
{
    private const int TTL_SECONDS = 172_800;

    public function recordTrip(): void
    {
        Cache::add($this->key('tripped_at'), Carbon::now()->toIso8601String(), self::TTL_SECONDS);
    }

    public function recordDegradedFill(): void
    {
        Cache::add($this->key('fills'), 0, self::TTL_SECONDS);
        Cache::increment($this->key('fills'));
    }

    /**
     * @return array{trippedAt: string|null, degradedFills: int}
     */
    public function today(): array
    {
        $trippedAt = Cache::get($this->key('tripped_at'));

        return [
            'trippedAt' => is_string($trippedAt) ? $trippedAt : null,
            'degradedFills' => (int) Cache::get($this->key('fills'), 0),
        ];
    }

    private function key(string $suffix): string
    {
        return 'ai:cost-ceiling:'.Carbon::today()->toDateString().':'.$suffix;
    }
}
