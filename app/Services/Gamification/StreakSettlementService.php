<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use Illuminate\Database\Eloquent\Collection;
use App\Actions\Gamification\SettleStreakRestTokensAction;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StreakSettlementService
{
    public const int MAX_HISTORY_WEEKS = 1040;

    public const int BATCH_WEEKS = 52;

    public function settle(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if ($locked === null) {
                return true;
            }

            $latest = self::latestClosedWeekEnding();
            if ($locked->streak_settled_through !== null && $locked->streak_settled_through->gte($latest)) {
                return true;
            }

            $earliest = WeeklySnapshot::query()
                ->where('user_id', $locked->id)
                ->min('week_ending');

            if ($earliest === null) {
                StreakRestToken::query()->where('user_id', $locked->id)->delete();
                $locked->forceFill(['streak_settled_through' => $latest])->saveQuietly();

                return true;
            }

            $first = Carbon::parse($earliest)->startOfDay();
            $cursor = $locked->streak_settled_through;
            $target = $latest;
            if ($cursor !== null) {
                $candidate = $cursor->copy()->addWeeks(self::BATCH_WEEKS);
                if ($candidate->lt($target)) {
                    $target = $candidate;
                }
            }
            $weeks = $first->diffInWeeks($target) + 1;

            if ($weeks > self::MAX_HISTORY_WEEKS) {
                throw new LogicException("Streak history for user {$locked->id} exceeds the ".self::MAX_HISTORY_WEEKS.'-week safety bound.');
            }

            $snapshots = WeeklySnapshot::query()
                ->where('user_id', $locked->id)
                ->where('week_ending', '<=', $target->toDateString())
                ->get(['week_ending', 'runs'])
                ->keyBy(fn (WeeklySnapshot $snapshot): string => $snapshot->week_ending->toDateString());
            $outcomes = $this->outcomes($first, $target, $snapshots);

            StreakRestToken::query()->where('user_id', $locked->id)->delete();
            foreach ($outcomes as $outcome) {
                StreakRestToken::query()->create([
                    'user_id' => $locked->id,
                    'earned_for_week_ending' => $outcome['earned'],
                    'spent_for_week_ending' => $outcome['spent'],
                ]);
            }

            $locked->forceFill(['streak_settled_through' => $target])->saveQuietly();

            return $target->gte($latest);
        });
    }

    public function allUsersSettled(): bool
    {
        $latest = self::latestClosedWeekEnding();

        return ! User::query()
            ->whereHas('weeklySnapshots')
            ->where(function ($query) use ($latest): void {
                $query->whereNull('streak_settled_through')
                    ->orWhere('streak_settled_through', '<', $latest->toDateString());
            })
            ->exists();
    }

    public static function latestClosedWeekEnding(): Carbon
    {
        return Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay()->subWeek();
    }

    /**
     * @param Collection<string, WeeklySnapshot> $snapshots
     * @return list<array{earned: string, spent: string|null}>
     */
    private function outcomes(Carbon $first, Carbon $target, Collection $snapshots): array
    {
        $streak = 0;
        /** @var list<array{earned: string, spent: string|null}> $tokens */
        $tokens = [];

        for ($week = $first->copy(); $week->lte($target); $week->addWeek()) {
            $date = $week->toDateString();
            $snapshot = $snapshots->get($date);
            $ran = $snapshot !== null && (int) $snapshot->runs > 0;

            if ($ran) {
                $streak++;
                if ($streak % SettleStreakRestTokensAction::ACCRUAL_EVERY_WEEKS === 0
                    && count(array_filter($tokens, fn (array $token): bool => $token['spent'] === null)) < SettleStreakRestTokensAction::MAX_HELD) {
                    $tokens[] = ['earned' => $date, 'spent' => null];
                }

                continue;
            }

            $tokenIndex = array_find_key($tokens, fn (array $token): bool => $token['spent'] === null);
            if ($streak > 0 && $tokenIndex !== null) {
                $tokens[$tokenIndex]['spent'] = $date;
                continue;
            }

            $streak = 0;
        }

        return $tokens;
    }
}
