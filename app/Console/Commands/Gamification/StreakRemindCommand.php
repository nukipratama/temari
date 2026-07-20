<?php

declare(strict_types=1);

namespace App\Console\Commands\Gamification;

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\StreakReminderNotification;
use App\Models\WeeklySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('streak:remind')]
#[Description('Nudge users whose live weekly streak has no run yet this week, before the week closes and the streak breaks')]
class StreakRemindCommand extends Command
{
    public function handle(): int
    {
        $weekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        // Users reachable on *any* channel who haven't opted out of the
        // weekly-recap notification (a missing preference row means all-on).
        // Iterating users rather than Telegram connections is what lets a
        // push-only user be nudged at all; via() re-checks per notifiable.
        $users = User::query()
            ->where('is_demo', false)
            ->whereDoesntHave('notificationPreference', fn (Builder $query): Builder => $query->where('weekly_recap', false))
            ->where($this->reachableOnAnyChannel(...))
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $streak = WeeklySnapshot::consecutiveWeekStreak($user->id);
            if ($streak < 1) {
                continue;
            }

            $currentWeekRuns = (int) WeeklySnapshot::query()
                ->where('user_id', $user->id)
                ->where('week_ending', $weekEnding->toDateString())
                ->value('runs');

            if ($currentWeekRuns > 0) {
                continue;
            }

            if (! $this->claim($user->id, $weekEnding)) {
                continue;
            }

            $user->notify(new StreakReminderNotification($streak));
            $sent++;
        }

        $this->info("Dispatched streak-at-risk reminder to {$sent} users.");

        return self::SUCCESS;
    }

    /**
     * Reachable on at least one notification channel: a live Telegram connection
     * or any web push subscription.
     *
     * @param  Builder<User>  $query
     */
    private function reachableOnAnyChannel(Builder $query): void
    {
        $query
            ->whereIn('id', TelegramConnection::query()->active()->select('user_id'))
            ->orWhereHas('pushSubscriptions');
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
}
