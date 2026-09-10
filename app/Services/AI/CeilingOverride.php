<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A today-only replacement for one athlete's `daily_cost_ceiling_per_user`, set
 * from /devtools when an operator wants to let a specific athlete keep
 * narrating past their usual slice.
 *
 * Cache-backed and keyed by local date, expiring at midnight, so an override
 * cannot outlive the day it was reasoned about: the operator never has to
 * remember to take it back off, and tomorrow reads config again.
 */
class CeilingOverride
{
    public function set(int $userId, float $ceiling): void
    {
        Cache::put($this->key($userId), $ceiling, Carbon::tomorrow());
    }

    /**
     * Redis returns a numeric it stored unserialized as a string, so the value
     * is read back through a numeric check rather than trusted to arrive a
     * float.
     */
    public function get(int $userId): ?float
    {
        $value = Cache::get($this->key($userId));

        return is_numeric($value) ? (float) $value : null;
    }

    public function clear(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return 'ceiling-override:'.$userId.':'.Carbon::today()->toDateString();
    }
}
