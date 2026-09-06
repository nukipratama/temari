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
 * `completed_at` was only ever stamped by {@see \App\Http\Controllers\RaceController::store()}
 * superseding one goal with another, so a race nobody replaced stayed
 * {@see RaceGoal::active()} forever. The periodizer kept planning against it:
 * {@see \App\Services\Run\Plan\PhaseSchedule::forRace()} counts the weeks left
 * until race day, and once that count went negative `plan:regenerate` threw
 * for that athlete — taking every athlete after them in the same run with it.
 *
 * Runs daily ahead of `plan:regenerate`, so a finished race is retired long
 * before the week count could go negative, and the plan falls back to the
 * self-scaled arc on its own. The row is never deleted — a past race is still
 * the athlete's record.
 */
#[Signature('plan:close-finished-races')]
#[Description('Stamp completed_at on every active race whose day has passed')]
class CloseFinishedRacesCommand extends Command
{
    public function handle(): int
    {
        $closed = RaceGoal::query()
            ->active()
            ->whereDate('race_date', '<', Carbon::today()->toDateString())
            ->update(['completed_at' => Carbon::now()]);

        $this->info(sprintf('Closed %d finished race goal(s).', $closed));

        return self::SUCCESS;
    }
}
