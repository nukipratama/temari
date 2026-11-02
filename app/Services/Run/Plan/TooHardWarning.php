<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\EasyTooHardNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tells the athlete, while the week is still open, what its hot easy days
 * will cost next week under {@see PlanAdapter::ranTooHard()}: once when one
 * more would set it, once when it is set.
 */
final readonly class TooHardWarning
{
    public function __construct(private PlanAdapter $adapter)
    {
    }

    public function warn(User $user, Carbon $runDate, Carbon $today): void
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        if ($user->is_demo || $runDate->lt($weekStart) || $runDate->gt($today->copy()->endOfDay())) {
            return;
        }

        $execution = $this->adapter->executionBetween($user, $weekStart, $today);
        $state = match (true) {
            PlanAdapter::ranTooHard($execution['ragged'], $execution['egregious_easy']) => EasyTooHardNotification::SET,
            PlanAdapter::ranTooHard($execution['ragged'] + 1, 0) => EasyTooHardNotification::ONE_MORE,
            default => null,
        };
        if ($state === null) {
            return;
        }

        $dedupeKey = EasyTooHardNotification::dedupeKeyFor($weekStart, $state);
        if (InboxNotification::query()->where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()
            || ! Cache::add("{$dedupeKey}:{$user->id}", true, $weekStart->copy()->addWeeks(2))) {
            return;
        }

        $user->notify(new EasyTooHardNotification($weekStart->toDateString(), $state, $execution['egregious_easy'] > 0));
    }
}
