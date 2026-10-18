<?php

declare(strict_types=1);

namespace App\Console\Commands\Gamification;

use App\Models\User;
use App\Services\Notifications\ChannelRouter;
use App\Services\Ops\MaintainerAlerter;
use App\Notifications\StreakReminderNotification;
use App\Models\WeeklySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('streak:remind')]
#[Description('Nudge users whose live weekly streak has no run yet this week, before the week closes and the streak breaks')]
class StreakRemindCommand extends Command
{
    public function handle(MaintainerAlerter $alerter): int
    {
        $weekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        // Reachable on *any* channel (via() re-checks per notifiable) and hasn't
        // turned the master switch off (missing preference row means all-on).
        // scopeReachable() must know about channel mutes, or this silently
        // enqueues notifications whose via() then returns [].
        $users = User::query()
            ->notDemo()
            ->whereDoesntHave('notificationPreference', fn (Builder $query): Builder => $query->where('notifications_enabled', false))
            ->where(app(ChannelRouter::class)->scopeReachable(...))
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $sent += $this->remind($user, $weekEnding) ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        if ($failed > 0) {
            $alerter->athletesFailed('streak:remind', $failed);
        } else {
            $alerter->athletesRecovered('streak:remind');
        }

        $this->info("Dispatched streak-at-risk reminder to {$sent} users.");

        return self::SUCCESS;
    }

    private function remind(User $user, Carbon $weekEnding): bool
    {
        $streak = WeeklySnapshot::consecutiveWeekStreak($user->id);
        if ($streak < 1) {
            return false;
        }

        $runs = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', $weekEnding->toDateString())
            ->value('runs');
        assert($runs === null || is_int($runs));
        $currentWeekRuns = (int) $runs;

        if ($currentWeekRuns > 0 || ! $this->claim($user->id, $weekEnding)) {
            return false;
        }

        try {
            $user->notify(new StreakReminderNotification($streak));
        } catch (Throwable $e) {
            $this->releaseClaim($user->id, $weekEnding);

            throw $e;
        }

        return true;
    }

    /**
     * Atomic once-per-user-per-week claim on the unique (user_id, week_ending)
     * pair: a same-week re-run (or a race) gets 0 inserted rows and skips.
     */
    private function claim(int $userId, Carbon $weekEnding): bool
    {
        $claimed = DB::table('streak_reminders')->insertOrIgnore([
            'user_id' => $userId,
            'week_ending' => $weekEnding->toDateString(),
            'created_at' => now(),
        ]);

        return $claimed !== 0;
    }

    private function releaseClaim(int $userId, Carbon $weekEnding): void
    {
        DB::table('streak_reminders')
            ->where('user_id', $userId)
            ->where('week_ending', $weekEnding->toDateString())
            ->delete();
    }
}
