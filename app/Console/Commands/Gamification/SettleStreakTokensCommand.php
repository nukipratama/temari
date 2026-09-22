<?php

declare(strict_types=1);

namespace App\Console\Commands\Gamification;

use App\Console\SchedulerChain;
use App\Jobs\Gamification\SettleStreakWeeksJob;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('streak:settle')]
#[Description('Settle the week that just closed against each user\'s weekly streak: mint a rest token, or spend one to forgive a runless week')]
class SettleStreakTokensCommand extends Command
{
    public function handle(): int
    {
        $users = User::query()
            ->whereIn('id', WeeklySnapshot::query()->select('user_id')->distinct())
            ->pluck('id');

        foreach ($users as $userId) {
            SettleStreakWeeksJob::dispatch((int) $userId)->afterCommit();
        }

        if ($users->isEmpty()) {
            SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE);
        }

        $this->info("Queued streak settlement for {$users->count()} users.");

        return self::SUCCESS;
    }
}
