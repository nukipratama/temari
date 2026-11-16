<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\EasyTooHardNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tells the athlete what this week's hot easy days cost next week, from {@see NextWeekOutlook}:
 * once when one more would set it, once when it is set, while the week is still open, and once
 * on Sunday evening when the session next week loses is settled.
 */
final readonly class TooHardWarning
{
    public function __construct(
        private PlanAdapter $adapter,
        private NextWeekOutlook $outlook,
    ) {
    }

    public function warn(User $user, Carbon $runDate, Carbon $today): void
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        if ($user->is_demo || $runDate->lt($weekStart) || $runDate->gt($today->copy()->endOfDay())) {
            return;
        }

        if ($this->adapter->executionBetween($user, $runDate, $runDate)['ragged'] === 0) {
            return;
        }

        $outlook = $this->outlook->for($user, $today);
        if ($outlook !== null) {
            $this->send($user, $weekStart, $outlook['state'], $outlook, $runDate->toDateString());
        }
    }

    public function lock(User $user, Carbon $today): bool
    {
        $outlook = $this->outlook->for($user, $today);
        if ($outlook === null || $outlook['state'] !== NextWeekOutlook::SET || $outlook['drop'] === null) {
            return false;
        }

        return $this->send($user, $today->copy()->startOfWeek(Carbon::MONDAY), EasyTooHardNotification::LOCKED, $outlook, null);
    }

    /** @param array{state: 'set'|'one_more', egregious: bool, hot_days: list<array{date: string, session_type: string, over_cap_minutes: int}>, drop: array{date: string, session_type: string, time_trial: bool}|null, blocker: string|null} $outlook */
    private function send(User $user, Carbon $weekStart, string $state, array $outlook, ?string $causeDate): bool
    {
        $dedupeKey = EasyTooHardNotification::dedupeKeyFor($weekStart, $state);
        if (InboxNotification::query()->where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()
            || ! Cache::add("{$dedupeKey}:{$user->id}", true, $weekStart->copy()->addWeeks(2))) {
            return false;
        }

        $user->notify(new EasyTooHardNotification($weekStart->toDateString(), $state, $outlook, $causeDate));

        return true;
    }
}
