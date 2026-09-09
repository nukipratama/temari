<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The trailing window of weekly snapshots behind a date, read once per
 * request per (athlete, week).
 *
 * Bound `scoped()` in AppServiceProvider — {@see \App\Services\Run\Plan\TrainingBaseline}
 * scans the same window from every entry point it has, and one Plan render
 * enters it four times. Home asks the same table for three different depths
 * behind three dates in the same week, so the read is widened to that week's
 * end and to {@see self::MAX_WEEKS} rows and every caller is served a slice of
 * it. {@see WeeklySnapshot::booted()} drops the memo on any write; a caller
 * that deletes snapshots in bulk must call {@see self::forget()} itself, since
 * a mass `delete()` fires no model events.
 */
class ResolveTrailingWeeksAction
{
    /** The deepest window any caller asks for, and therefore the one that is read. */
    public const int MAX_WEEKS = 12;

    /** @var array<string, Collection<int, WeeklySnapshot>> */
    private array $memo = [];

    /** @return Collection<int, WeeklySnapshot> */
    public function __invoke(int $userId, string $asOfDate, int $weeks): Collection
    {
        if ($weeks > self::MAX_WEEKS) {
            return $this->fetch($userId, $asOfDate, $weeks);
        }

        return $this->week($userId, $asOfDate)
            ->filter(fn (WeeklySnapshot $row): bool => $row->week_ending->toDateString() <= $asOfDate)
            ->take($weeks)
            ->values();
    }

    public function forget(int $userId): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $userId.'|')) {
                unset($this->memo[$key]);
            }
        }
    }

    /**
     * One extra row beyond MAX_WEEKS: widening the ceiling to the week's end
     * can pull in the in-progress week, which the slice above then drops.
     *
     * @return Collection<int, WeeklySnapshot>
     */
    private function week(int $userId, string $asOfDate): Collection
    {
        $weekEnding = Carbon::parse($asOfDate)->endOfWeek(Carbon::SUNDAY)->toDateString();

        return $this->memo[$userId.'|'.$weekEnding] ??= $this->fetch($userId, $weekEnding, self::MAX_WEEKS + 1);
    }

    /** @return Collection<int, WeeklySnapshot> */
    private function fetch(int $userId, string $ceiling, int $weeks): Collection
    {
        return WeeklySnapshot::query()
            ->where('user_id', $userId)
            ->where('week_ending', '<=', $ceiling)
            ->orderByDesc('week_ending')
            ->limit($weeks)
            ->get();
    }
}
