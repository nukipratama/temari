<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\SchedulerChain;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Gamification\StreakSettlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('schedule:monday-check')]
#[Description('Alert the maintainer once if a Monday scheduler entry has not succeeded, or an athlete is still unsettled, by 06:00')]
class MondayCheckCommand extends Command
{
    public function handle(StreakSettlementService $settlement, MaintainerAlerter $alerter): int
    {
        $overdue = [];

        $unsettled = $settlement->unsettledUsers()->count();
        if ($unsettled > 0) {
            $athletes = $unsettled === 1 ? '1 athlete' : "{$unsettled} athletes";
            $overdue[] = "streak:settle ({$athletes} unsettled)";
        }

        foreach ([SchedulerChain::PLAN_CLOSE_FINISHED_RACES, SchedulerChain::PLAN_SCORE_COMPLIANCE] as $command) {
            if (! SchedulerChain::isDoneToday($command)) {
                $overdue[] = $command;
            }
        }

        if (! SchedulerChain::isDoneThisWeek(SchedulerChain::PLAN_REGENERATE)) {
            $overdue[] = SchedulerChain::PLAN_REGENERATE;
        }

        if ($overdue === []) {
            $this->info('Every Monday entry has succeeded.');

            return self::SUCCESS;
        }

        $alerter->mondayEntriesOverdue($overdue);
        $this->warn('Overdue: '.implode(', ', $overdue));

        return self::SUCCESS;
    }
}
