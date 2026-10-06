<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\SchedulerChain;
use App\Listeners\RecordScheduledTaskRun;
use App\Models\ScheduledTaskRun;
use App\Services\AI\MaintainerAlerter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

#[Signature('schedule:check-late')]
#[Description('Page once per incident for every scheduled entry that is late, and send one line when it is back on time')]
class ScheduleLateCheckCommand extends Command
{
    public function handle(Schedule $schedule, MaintainerAlerter $alerter): int
    {
        $runs = ScheduledTaskRun::query()->get()->keyBy('command');
        $late = [];

        foreach ($schedule->events() as $event) {
            $command = RecordScheduledTaskRun::label($event);
            $run = $runs->get($command);

            if (SchedulerChain::isLate($command, $run)) {
                $late[] = $command;
                $alerter->schedulerLate($command, $run?->last_run_at);
            } else {
                $alerter->schedulerOnTime($command);
            }
        }

        $this->info($late === [] ? 'Every scheduled entry is on time.' : 'Late: '.implode(', ', $late));

        return self::SUCCESS;
    }
}
