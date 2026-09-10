<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The time of day an athlete usually starts running, as minutes since midnight
 * local. Derived from the runs they have already logged rather than asked for in
 * onboarding: the answer they would type is the time they *intend* to run, and
 * the whole point of the morning push is to land at the time they actually do.
 *
 * The median, not the mean: one 21:00 night race would drag an average across
 * half an hour, and the median is unmoved by it.
 */
final class UsualRunTime
{
    /**
     * A count-based window rather than a fixed number of days: at three runs a
     * week a 28-day window leaves a light runner permanently under
     * {@see self::MIN_SAMPLES} and permanently on the fallback, while twenty
     * runs is roughly seven weeks for that same athlete and roughly two for a
     * daily one — recent either way.
     */
    public const int SAMPLE_SIZE = 20;

    /** Below this, the median is one habit and two accidents. */
    public const int MIN_SAMPLES = 5;

    public const int FALLBACK_MINUTE = 6 * 60;

    /**
     * Memoized for the athlete's day: the push command calls this once per
     * 15-minute tick for every push-reachable athlete, but the median only
     * moves when a run lands, so {@see clearCache} at ingest is what makes
     * this fresh. The date in the key is what makes it roll over at midnight.
     */
    public function forUser(int $userId): int
    {
        return (int) Cache::remember(
            self::cacheKey($userId, Carbon::today()->toDateString()),
            Carbon::tomorrow(),
            fn (): int => $this->computeForUser($userId),
        );
    }

    public static function cacheKey(int $userId, string $day): string
    {
        return "usual-run-time:{$userId}:{$day}";
    }

    /** Called wherever an activity enters or leaves a user's history. */
    public static function clearCache(User $user): void
    {
        Cache::forget(self::cacheKey($user->id, Carbon::today()->toDateString()));
    }

    private function computeForUser(int $userId): int
    {
        $minutes = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->orderByDesc('activity_details.start_date_local')
            ->limit(self::SAMPLE_SIZE)
            ->pluck('activity_details.start_date_local')
            ->map(function (string $start): int {
                $at = Carbon::parse($start);

                return $at->hour * 60 + $at->minute;
            })
            ->sort()
            ->values();

        if ($minutes->count() < self::MIN_SAMPLES) {
            return self::FALLBACK_MINUTE;
        }

        $middle = intdiv($minutes->count(), 2);

        return $minutes->count() % 2 === 1
            ? (int) $minutes[$middle]
            : (int) round(((int) $minutes[$middle - 1] + (int) $minutes[$middle]) / 2);
    }
}
