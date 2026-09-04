<?php

declare(strict_types=1);

namespace App\Actions\Gamification;

use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;

/**
 * Settles one closed week against the weekly streak: mints a rest token when
 * the streak completes another training cycle, or spends one to forgive the
 * week when it closed with no run.
 *
 * Spending is automatic. There is no surface on which a user could choose to
 * spend one, and a token the user has to remember to play would silently fail
 * the runner it exists to protect.
 */
class SettleStreakRestTokensAction
{
    /**
     * One token per completed training cycle. Matches the periodizer's own
     * 3-build-1-deload rhythm, so a token lands exactly as a deload week
     * comes due.
     */
    public const int ACCRUAL_EVERY_WEEKS = 4;

    /** Held at once, so a long streak cannot bank enough weeks to make itself meaningless. */
    public const int MAX_HELD = 2;

    /** @return 'accrued'|'spent'|'none' */
    public function __invoke(User $user, Carbon $closedWeekEnding): string
    {
        if ($this->ranIn($user, $closedWeekEnding)) {
            return $this->accrue($user, $closedWeekEnding) ? 'accrued' : 'none';
        }

        return $this->spend($user, $closedWeekEnding) ? 'spent' : 'none';
    }

    private function accrue(User $user, Carbon $closedWeekEnding): bool
    {
        $streak = WeeklySnapshot::consecutiveWeekStreak($user->id);

        if ($streak === 0 || $streak % self::ACCRUAL_EVERY_WEEKS !== 0) {
            return false;
        }

        if (StreakRestToken::unspentCountForUser($user->id) >= self::MAX_HELD) {
            return false;
        }

        return StreakRestToken::query()->insertOrIgnore([
            'user_id' => $user->id,
            'earned_for_week_ending' => $closedWeekEnding->toDateString(),
            'spent_for_week_ending' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]) !== 0;
    }

    private function spend(User $user, Carbon $closedWeekEnding): bool
    {
        if (! $this->hadLiveStreakBefore($user, $closedWeekEnding)) {
            return false;
        }

        $alreadySpentForThisWeek = StreakRestToken::query()
            ->where('user_id', $user->id)
            ->where('spent_for_week_ending', $closedWeekEnding->toDateString())
            ->exists();

        if ($alreadySpentForThisWeek) {
            return false;
        }

        $token = StreakRestToken::query()
            ->where('user_id', $user->id)
            ->whereNull('spent_for_week_ending')
            ->orderBy('id')
            ->first();

        if ($token === null) {
            return false;
        }

        $token->update(['spent_for_week_ending' => $closedWeekEnding->toDateString()]);

        return true;
    }

    /**
     * Whether forgiving this week would actually bridge to a week the user ran,
     * so a token is never burned on a user who has no streak to save.
     */
    private function hadLiveStreakBefore(User $user, Carbon $closedWeekEnding): bool
    {
        $forgiven = StreakRestToken::forgivenWeekEndings($user->id);
        $week = $closedWeekEnding->copy()->subDays(7);

        while (isset($forgiven[$week->toDateString()])) {
            $week->subDays(7);
        }

        return $this->ranIn($user, $week);
    }

    private function ranIn(User $user, Carbon $weekEnding): bool
    {
        return WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', $weekEnding->toDateString())
            ->where('runs', '>', 0)
            ->exists();
    }
}
