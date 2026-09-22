<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StreakSettlementService
{
    public const int MAX_HISTORY_WEEKS = 1040;

    public const int BATCH_WEEKS = 52;

    public const int ACCRUAL_EVERY_WEEKS = 4;

    public const int MAX_HELD = 2;

    public function markDirty(int $userId, Carbon $from): void
    {
        $from = $from->copy()->startOfDay();
        DB::transaction(function () use ($userId, $from): void {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null
                || $user->streak_settled_through === null
                || $from->gt($user->streak_settled_through)) {
                return;
            }

            $dirtyFrom = $user->streak_settlement_dirty_from;
            if ($dirtyFrom !== null && $dirtyFrom->lte($from)) {
                return;
            }

            $user->forceFill(['streak_settlement_dirty_from' => $from])->saveQuietly();
        });
    }

    public function settle(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if ($locked === null) {
                return true;
            }

            $latest = self::latestClosedWeekEnding();
            $dirtyFrom = $locked->streak_settlement_dirty_from;
            $dirty = $dirtyFrom !== null && $dirtyFrom->lte($latest);
            if ($locked->streak_settled_through !== null
                && $locked->streak_settled_through->gte($latest)
                && ! $dirty) {
                return true;
            }

            $earliest = WeeklySnapshot::query()
                ->where('user_id', $locked->id)
                ->min('week_ending');

            if ($earliest === null) {
                StreakRestToken::query()->where('user_id', $locked->id)->delete();
                $locked->forceFill([
                    'streak_settled_through' => $latest,
                    'streak_settlement_streak' => 0,
                    'streak_settlement_dirty_from' => null,
                ])->saveQuietly();

                return true;
            }

            $first = Carbon::parse($earliest)->startOfDay();
            $this->assertHistoryWithinBound($locked->id, $first, $latest);

            $cursor = $locked->streak_settled_through;
            $rebuild = $cursor === null || $dirty;
            if ($rebuild) {
                StreakRestToken::query()->where('user_id', $locked->id)->delete();
                $start = $first;
                $streak = 0;
            } else {
                $start = $cursor->copy()->addWeek();
                if ($start->lt($first)) {
                    $start = $first;
                }
                $streak = (int) ($locked->streak_settlement_streak ?? 0);
            }

            if ($start->gt($latest)) {
                $locked->forceFill([
                    'streak_settled_through' => $latest,
                    'streak_settlement_dirty_from' => $dirty ? null : $locked->streak_settlement_dirty_from,
                ])->saveQuietly();

                return true;
            }

            $target = $start->copy()->addWeeks(self::BATCH_WEEKS - 1);
            if ($target->gt($latest)) {
                $target = $latest;
            }

            $snapshots = WeeklySnapshot::query()
                ->where('user_id', $locked->id)
                ->whereBetween('week_ending', [$start->toDateString(), $target->toDateString()])
                ->get(['week_ending', 'runs'])
                ->keyBy(fn (WeeklySnapshot $snapshot): string => $snapshot->week_ending->toDateString());

            $streak = $this->settleRange($locked, $start, $target, $streak, $snapshots);
            $locked->forceFill([
                'streak_settled_through' => $target,
                'streak_settlement_streak' => $streak,
                'streak_settlement_dirty_from' => $rebuild ? null : $locked->streak_settlement_dirty_from,
            ])->saveQuietly();

            return $target->gte($latest);
        });
    }

    public function allUsersSettled(): bool
    {
        $latest = self::latestClosedWeekEnding();

        return ! User::query()
            ->notDemo()
            ->whereHas('weeklySnapshots')
            ->where(function ($query) use ($latest): void {
                $query->whereNull('streak_settled_through')
                    ->orWhere('streak_settled_through', '<', $latest->toDateString())
                    ->orWhere(fn ($dirty) => $dirty
                        ->whereNotNull('streak_settlement_dirty_from')
                        ->where('streak_settlement_dirty_from', '<=', $latest->toDateString()));
            })
            ->exists();
    }

    public static function latestClosedWeekEnding(): Carbon
    {
        return Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay()->subWeek();
    }

    private function assertHistoryWithinBound(int $userId, Carbon $first, Carbon $latest): void
    {
        if ($first->lte($latest) && $first->diffInWeeks($latest) + 1 > self::MAX_HISTORY_WEEKS) {
            throw new LogicException("Streak history for user {$userId} exceeds the ".self::MAX_HISTORY_WEEKS.'-week safety bound.');
        }
    }

    /**
     * @param Collection<string, WeeklySnapshot> $snapshots
     */
    private function settleRange(User $user, Carbon $start, Carbon $target, int $streak, Collection $snapshots): int
    {
        for ($week = $start->copy(); $week->lte($target); $week->addWeek()) {
            $date = $week->toDateString();
            $snapshot = $snapshots->get($date);

            if ($snapshot !== null && (int) $snapshot->runs > 0) {
                $streak++;
                if ($streak % self::ACCRUAL_EVERY_WEEKS === 0
                    && StreakRestToken::unspentCountForUser($user->id) < self::MAX_HELD) {
                    StreakRestToken::query()->create([
                        'user_id' => $user->id,
                        'earned_for_week_ending' => $date,
                        'spent_for_week_ending' => null,
                    ]);
                }

                continue;
            }

            if ($streak > 0) {
                $token = StreakRestToken::query()
                    ->where('user_id', $user->id)
                    ->whereNull('spent_for_week_ending')
                    ->orderBy('id')
                    ->first();

                if ($token !== null) {
                    $token->update(['spent_for_week_ending' => $date]);
                    continue;
                }
            }

            $streak = 0;
        }

        return $streak;
    }
}
