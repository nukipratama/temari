<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\PlanAdaptation;
use App\Models\User;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Run\Plan\TooHardWarning;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('plan:lock-next-week')]
#[Description('Tell each athlete whose hot easy days cost next week a quality session which session it is, before the week starts')]
class NextWeekLockCommand extends Command
{
    public function handle(TooHardWarning $warning, MaintainerAlerter $alerter): int
    {
        $today = Carbon::today();
        $users = User::query()
            ->notDemo()
            ->whereDoesntHave('notificationPreference', fn (Builder $query): Builder => $query->where('notifications_enabled', false))
            ->whereIn('id', PlanAdaptation::query()
                ->where('week_start', $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
                ->whereNotNull('next_week_drop')
                ->select('user_id'))
            ->get();

        $sent = 0;
        $failed = 0;
        foreach ($users as $user) {
            try {
                $sent += $warning->lock($user, $today) ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        if ($failed > 0) {
            $alerter->athletesFailed('plan:lock-next-week', $failed);
        } else {
            $alerter->athletesRecovered('plan:lock-next-week');
        }

        $this->info("Told {$sent} athletes which session next week loses.");

        return self::SUCCESS;
    }
}
