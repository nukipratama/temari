<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Enums\RaceOutcome;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceOutcomeNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('race:ask-outcome')]
#[Description('Ask each athlete whose race was yesterday how it went')]
class RaceOutcomeAskCommand extends Command
{
    public function handle(): int
    {
        $races = RaceGoal::query()
            ->where('outcome', RaceOutcome::Pending)
            ->whereDate('race_date', Carbon::yesterday())
            ->whereIn('user_id', User::query()
                ->notDemo()
                ->whereDoesntHave('notificationPreference', fn (Builder $preference): Builder => $preference->where('notifications_enabled', false))
                ->select('id'))
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($races as $race) {
            if (! $this->claim($race)) {
                continue;
            }

            try {
                $race->user->notify(new RaceOutcomeNotification($race));
            } catch (Throwable $e) {
                $this->releaseClaim($race);

                throw $e;
            }

            $sent++;
        }

        $this->info("Asked {$sent} users how their race went.");

        return self::SUCCESS;
    }

    private function claim(RaceGoal $race): bool
    {
        return DB::table('race_goals')
            ->where('id', $race->id)
            ->whereNull('outcome_asked_at')
            ->update(['outcome_asked_at' => now()]) === 1;
    }

    private function releaseClaim(RaceGoal $race): void
    {
        DB::table('race_goals')->where('id', $race->id)->update(['outcome_asked_at' => null]);
    }
}
