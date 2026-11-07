<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\InboxNotification;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\PlanChangedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tells the athlete when a background rebuild changes a session they can still run this week:
 * its type, hard minutes or pace band. Each session date is told at most once.
 */
final readonly class PlanChangeNotice
{
    public const int SETTLE_MINUTES = 10;

    /**
     * The open week's decisions before a rebuild, empty when nothing should be announced: the demo
     * athlete, or the week's first build, which writes the week rather than changing it.
     *
     * @return array<string, array{session_type: string, hard_minutes: int, pace_band: string|null}>
     */
    public function baseline(User $user, Carbon $today): array
    {
        $weekStarted = PlanAdaptation::query()
            ->where('user_id', $user->id)
            ->where('week_start', $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
            ->exists();

        return $user->is_demo || ! $weekStarted ? [] : self::decisions($user->id, $today);
    }

    /** @param array<string, array{session_type: string, hard_minutes: int, pace_band: string|null}> $baseline */
    public function announce(User $user, array $baseline, Carbon $today): void
    {
        if ($baseline === []) {
            return;
        }

        foreach (self::decisions($user->id, $today) as $date => $decision) {
            $was = $baseline[$date] ?? null;
            if ($was === null || $was === $decision || ! self::claim($user, $date)) {
                continue;
            }

            $user->notify(new PlanChangedNotification($date, $was)->delay(now()->addMinutes(self::SETTLE_MINUTES))->afterCommit());
        }
    }

    /** @return array{session_type: string, hard_minutes: int, pace_band: string|null} */
    public static function decisionOf(PlannedSession $session): array
    {
        return [
            'session_type' => $session->session_type->value,
            'hard_minutes' => $session->prescribed_hard_minutes ?? 0,
            'pace_band' => $session->prescribed_pace_band?->value,
        ];
    }

    public static function release(User $user, string $date): void
    {
        if (! self::told($user, $date)) {
            Cache::forget(self::claimKey($user, $date));
        }
    }

    /** @return array<string, array{session_type: string, hard_minutes: int, pace_band: string|null}> */
    private static function decisions(int $userId, Carbon $today): array
    {
        return PlannedSession::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$today->toDateString(), $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()])
            ->where('status', PlannedSessionStatus::Planned)
            ->where('pinned', false)
            ->get(['date', 'session_type', 'prescribed_hard_minutes', 'prescribed_pace_band'])
            ->mapWithKeys(fn (PlannedSession $session): array => [$session->date->toDateString() => self::decisionOf($session)])
            ->all();
    }

    private static function claim(User $user, string $date): bool
    {
        return ! self::told($user, $date)
            && Cache::add(self::claimKey($user, $date), true, Carbon::parse($date)->addWeek());
    }

    private static function told(User $user, string $date): bool
    {
        return InboxNotification::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', PlanChangedNotification::dedupeKeyFor($date))
            ->exists();
    }

    private static function claimKey(User $user, string $date): string
    {
        return PlanChangedNotification::dedupeKeyFor($date).":{$user->id}";
    }
}
