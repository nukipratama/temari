<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use Illuminate\Support\Collection;

/**
 * The athlete's personal records over the standard race distances, read once
 * per request.
 *
 * Bound `scoped()` in AppServiceProvider — {@see \App\Services\Run\Metrics\VdotEstimator}
 * re-reads the whole set on every estimate, and one Plan render estimates four
 * times. {@see PersonalRecord::booted()} drops the memo on any write; a caller
 * that rebuilds the set with a mass `delete()` must call {@see self::forget()}
 * itself, since that fires no model events.
 */
class ResolveDistanceRecordsAction
{
    /** @var array<int, Collection<int, PersonalRecord>> */
    private array $memo = [];

    /** @return Collection<int, PersonalRecord> */
    public function __invoke(int $userId): Collection
    {
        return $this->memo[$userId] ??= PersonalRecord::query()
            ->where('user_id', $userId)
            ->whereIn('category', array_map(
                static fn (PrCategory $c): string => $c->value,
                PrCategory::distances(),
            ))
            ->get();
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
    }
}
