<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\User;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Run\Metrics\FitnessImprovementChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('fitness:notify-improvement')]
#[Description('Tell each athlete whose supported race time improved by a meaningful step since the last note')]
class FitnessNotifyImprovementCommand extends Command
{
    /** The day the scan also takes athletes with no new run, so a rise that matured over rest is still noted. */
    public const int SWEEP_DAY = Carbon::MONDAY;

    public const int SWEEP_AFTER_DAYS = 7;

    private const int CHUNK_SIZE = 100;

    public function handle(FitnessImprovementChecker $checker, MaintainerAlerter $alerter): int
    {
        $today = Carbon::today();
        $checkedAt = Carbon::now();
        $seeded = 0;
        $sent = 0;
        $failed = 0;

        User::query()->notDemo()->where(fn (Builder $query) => $this->dueForCheck($query, $today))->chunkById(self::CHUNK_SIZE, function (Collection $users) use ($checker, $today, $checkedAt, &$seeded, &$sent, &$failed): void {
            foreach ($users as $user) {
                /** @var User $user */
                try {
                    match ($checker->check($user, $today, $checkedAt)) {
                        'seeded' => $seeded++,
                        'sent' => $sent++,
                        default => null,
                    };
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
            }
        });

        if ($failed > 0) {
            $alerter->athletesFailed('fitness:notify-improvement', $failed);
        } else {
            $alerter->athletesRecovered('fitness:notify-improvement');
        }

        $this->info("Seeded {$seeded} baselines and noted an improvement for {$sent} users.");

        return self::SUCCESS;
    }

    /** @param  Builder<User>  $query */
    private function dueForCheck(Builder $query, Carbon $today): void
    {
        $query->whereNull('fitness_checked_at')
            ->orWhereHas(
                'activities',
                fn (Builder $activities): Builder => $activities->whereColumn('activities.analyzed_at', '>=', 'users.fitness_checked_at'),
            )
            ->when(
                $today->dayOfWeek === self::SWEEP_DAY,
                fn (Builder $query): Builder => $query->orWhere('fitness_checked_at', '<', $today->copy()->subDays(self::SWEEP_AFTER_DAYS - 1)),
            );
    }
}
