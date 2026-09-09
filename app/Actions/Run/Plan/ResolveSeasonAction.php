<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\Season;
use Illuminate\Support\Carbon;

/**
 * The athlete's season arc, read once per request.
 *
 * Bound `scoped()` in AppServiceProvider — one deferred Plan render asks for
 * the latest season three times and for the arc covering today three more.
 * The dated read is derived from the latest one whenever that season has
 * already started, so both questions cost a single statement.
 * {@see Season::booted()} drops the memo on any write.
 */
class ResolveSeasonAction
{
    /** @var array<int, Season|null> */
    private array $latest = [];

    /** @var array<string, Season|null> */
    private array $asOf = [];

    public function latest(int $userId): ?Season
    {
        if (array_key_exists($userId, $this->latest)) {
            return $this->latest[$userId];
        }

        return $this->latest[$userId] = Season::query()
            ->where('user_id', $userId)
            ->orderByDesc('starts_at')
            ->first();
    }

    public function currentAsOf(int $userId, Carbon $asOf): ?Season
    {
        $date = $asOf->toDateString();
        $key = $userId.'|'.$date;

        if (array_key_exists($key, $this->asOf)) {
            return $this->asOf[$key];
        }

        $latest = $this->latest($userId);
        if ($latest === null || $latest->starts_at->toDateString() <= $date) {
            return $this->asOf[$key] = $latest;
        }

        return $this->asOf[$key] = Season::query()
            ->where('user_id', $userId)
            ->whereDate('starts_at', '<=', $date)
            ->orderByDesc('starts_at')
            ->first();
    }

    public function forget(int $userId): void
    {
        unset($this->latest[$userId]);

        foreach (array_keys($this->asOf) as $key) {
            if (str_starts_with($key, $userId.'|')) {
                unset($this->asOf[$key]);
            }
        }
    }
}
