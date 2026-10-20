<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\RaceGoal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retires a race the athlete has already run.
 *
 * Runs daily ahead of `plan:regenerate`, so a race nobody replaced stops being
 * {@see RaceGoal::active()} before
 * {@see \App\Services\Run\Plan\PhaseSchedule::forRace()}'s week count goes
 * negative. The row is never deleted — a past race is still the athlete's
 * record.
 */
#[Signature('plan:close-finished-races')]
#[Description('Stamp completed_at on every active race whose day has passed')]
class CloseFinishedRacesCommand extends Command
{
    public function handle(): int
    {
        $closed = RaceGoal::query()
            ->active()
            ->where('race_date', '<', Carbon::today()->toDateString())
            ->update(['completed_at' => Carbon::now()]);

        $this->info(sprintf('Closed %d finished race goal(s).', $closed));

        return self::SUCCESS;
    }
}
