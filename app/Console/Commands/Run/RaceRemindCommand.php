<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceTomorrowNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('race:remind')]
#[Description('Tell each athlete whose goal race is tomorrow that it is tomorrow')]
class RaceRemindCommand extends Command
{
    public function handle(): int
    {
        $races = RaceGoal::query()
            ->active()
            ->whereDate('race_date', Carbon::tomorrow())
            ->whereIn('user_id', User::query()->notDemo()->where($this->wantsNotifications(...))->select('id'))
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($races as $race) {
            if (! $this->claim($race)) {
                continue;
            }

            try {
                $race->user->notify(new RaceTomorrowNotification($race));
            } catch (Throwable $e) {
                $this->releaseClaim($race);

                throw $e;
            }

            $sent++;
        }

        $this->info("Dispatched race-day reminder to {$sent} users.");

        return self::SUCCESS;
    }

    /**
     * The master switch names this reminder among what it governs, and `via()`
     * re-checks it per notifiable — so filtering here is what keeps the reported
     * count honest rather than counting sends that resolve to no channel at all.
     * A missing preference row means all-on.
     *
     * @param  Builder<User>  $query
     */
    private function wantsNotifications(Builder $query): void
    {
        $query->whereDoesntHave(
            'notificationPreference',
            fn (Builder $preference): Builder => $preference->where('notifications_enabled', false),
        );
    }

    private function claim(RaceGoal $race): bool
    {
        return DB::table('race_goals')
            ->where('id', $race->id)
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->whereNull('reminded_for_date')->orWhereColumn('reminded_for_date', '<>', 'race_date'))
            ->update(['reminded_for_date' => DB::raw('race_date')]) === 1;
    }

    private function releaseClaim(RaceGoal $race): void
    {
        DB::table('race_goals')->where('id', $race->id)->update(['reminded_for_date' => null]);
    }
}
