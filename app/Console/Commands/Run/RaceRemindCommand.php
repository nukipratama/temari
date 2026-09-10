<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\InboxNotification;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceTomorrowNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('race:remind')]
#[Description('Tell each athlete whose goal race is tomorrow that it is tomorrow')]
class RaceRemindCommand extends Command
{
    public function handle(): int
    {
        $races = RaceGoal::query()
            ->active()
            ->whereDate('race_date', Carbon::tomorrow())
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($races as $race) {
            if ($this->alreadyTold($race)) {
                continue;
            }

            $race->user->notify(new RaceTomorrowNotification($race));
            $sent++;
        }

        $this->info("Dispatched race-day reminder to {$sent} users.");

        return self::SUCCESS;
    }

    /**
     * The inbox row is the durable record of the send, so its unique (user,
     * dedupe key) pair is also the claim — no second table for a once-per-race
     * reminder. Safe as a read-then-write because exactly one scheduler runs
     * this and it holds an overlap lock while it does.
     */
    private function alreadyTold(RaceGoal $race): bool
    {
        return InboxNotification::query()
            ->where('user_id', $race->user_id)
            ->where('dedupe_key', RaceTomorrowNotification::dedupeKeyFor($race))
            ->exists();
    }
}
