<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Enums\RaceOutcome;
use App\Models\InboxNotification;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceOutcomeNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

#[Signature('race:ask-outcome')]
#[Description('Ask each athlete whose race was yesterday how it went')]
class RaceOutcomeAskCommand extends Command
{
    public function handle(): int
    {
        $races = RaceGoal::query()
            ->where('outcome', RaceOutcome::Pending)
            ->whereDate('race_date', Carbon::yesterday())
            ->whereIn('user_id', User::query()->notDemo()->where($this->wantsNotifications(...))->select('id'))
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($races as $race) {
            if ($this->alreadyAsked($race)) {
                continue;
            }

            $race->user->notify(new RaceOutcomeNotification($race));
            $sent++;
        }

        $this->info("Asked {$sent} users how their race went.");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<User>  $query
     */
    private function wantsNotifications(Builder $query): void
    {
        $query->whereDoesntHave(
            'notificationPreference',
            fn (Builder $preference): Builder => $preference->where('notifications_enabled', false),
        );
    }

    private function alreadyAsked(RaceGoal $race): bool
    {
        return InboxNotification::query()
            ->where('user_id', $race->user_id)
            ->where('dedupe_key', RaceOutcomeNotification::dedupeKeyFor($race))
            ->exists();
    }
}
