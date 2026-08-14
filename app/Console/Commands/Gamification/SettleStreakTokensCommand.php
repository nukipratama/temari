<?php

declare(strict_types=1);

namespace App\Console\Commands\Gamification;

use App\Actions\Gamification\SettleStreakRestTokensAction;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('streak:settle')]
#[Description('Settle the week that just closed against each user\'s weekly streak: mint a rest token, or spend one to forgive a runless week')]
class SettleStreakTokensCommand extends Command
{
    public function handle(SettleStreakRestTokensAction $settle): int
    {
        $closedWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay()->subDays(7);

        $accrued = 0;
        $spent = 0;

        User::query()
            ->whereIn('id', WeeklySnapshot::query()->select('user_id')->distinct())
            ->eachById(function (User $user) use ($settle, $closedWeekEnding, &$accrued, &$spent): void {
                match ($settle($user, $closedWeekEnding)) {
                    'accrued' => $accrued++,
                    'spent' => $spent++,
                    'none' => null,
                };
            });

        $this->info("Week ending {$closedWeekEnding->toDateString()}: minted {$accrued} rest tokens, spent {$spent}.");

        return self::SUCCESS;
    }
}
