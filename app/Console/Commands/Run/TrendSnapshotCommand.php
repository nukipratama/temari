<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trend:snapshot-daily')]
#[Description("Write today's VDOT/pace-consistency snapshot row for every user")]
class TrendSnapshotCommand extends Command
{
    public function handle(TrendSnapshotWriter $writer): int
    {
        // Every user, not just users active in the last N days like
        // DailyBriefingCommand — a rest week must still grow a snapshot row,
        // or the "not enough history yet" empty state never resolves for a
        // user who happens to be resting. Includes the demo user: this is
        // free local computation (VdotEstimator + StreamSummary), not an
        // LLM/Strava call, so there's no cost to exclude it from — see
        // DemoBillingExclusionTest.
        $users = User::query()->get();

        foreach ($users as $user) {
            $writer->writeToday($user);
        }

        $this->info("Wrote today's trend snapshot for {$users->count()} users.");

        return self::SUCCESS;
    }
}
