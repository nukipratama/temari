<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\WeeklySnapshot;
use Illuminate\Support\Collection;

/**
 * The trailing window of weekly snapshots behind a date, read once per
 * request per (athlete, date, window).
 *
 * Bound `scoped()` in AppServiceProvider — {@see \App\Services\Run\Plan\TrainingBaseline}
 * scans the same window from every entry point it has, and one Plan render
 * enters it four times. {@see WeeklySnapshot::booted()} drops the memo on any
 * write; a caller that deletes snapshots in bulk must call {@see self::forget()}
 * itself, since a mass `delete()` fires no model events.
 */
class ResolveTrailingWeeksAction
{
    /** @var array<string, Collection<int, WeeklySnapshot>> */
    private array $memo = [];

    /** @return Collection<int, WeeklySnapshot> */
    public function __invoke(int $userId, string $asOfDate, int $weeks): Collection
    {
        $key = $userId.'|'.$asOfDate.'|'.$weeks;

        return $this->memo[$key] ??= WeeklySnapshot::query()
            ->where('user_id', $userId)
            ->where('week_ending', '<=', $asOfDate)
            ->orderByDesc('week_ending')
            ->limit($weeks)
            ->get();
    }

    public function forget(int $userId): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $userId.'|')) {
                unset($this->memo[$key]);
            }
        }
    }
}
