<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Enums\NotificationKind;
use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\FitnessImprovedNotification;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Run\Metrics\VdotEstimator;
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
    public const float IMPROVEMENT_THRESHOLD_VDOT = 0.5;

    public const int NOTE_INTERVAL_DAYS = 7;

    private const int CHUNK_SIZE = 100;

    public function handle(VdotEstimator $estimator, MaintainerAlerter $alerter): int
    {
        $today = Carbon::today();
        $checkedAt = Carbon::now();
        $seeded = 0;
        $sent = 0;
        $failed = 0;

        User::query()->notDemo()->where($this->hasRunSinceCheck(...))->chunkById(self::CHUNK_SIZE, function (Collection $users) use ($estimator, $today, $checkedAt, &$seeded, &$sent, &$failed): void {
            foreach ($users as $user) {
                /** @var User $user */
                try {
                    $outcome = $this->check($estimator, $user, $today);
                    match ($outcome) {
                        'seeded' => $seeded++,
                        'sent' => $sent++,
                        default => null,
                    };
                    // A rise held back by the weekly note interval stays unchecked, so the next run retries it.
                    if ($outcome !== 'held') {
                        User::query()->whereKey($user->id)->update(['fitness_checked_at' => $checkedAt]);
                    }
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
    private function hasRunSinceCheck(Builder $query): void
    {
        $query->whereNull('fitness_checked_at')->orWhereHas(
            'activities',
            fn (Builder $activities): Builder => $activities->whereColumn('activities.analyzed_at', '>', 'users.fitness_checked_at'),
        );
    }

    private function check(VdotEstimator $estimator, User $user, Carbon $today): ?string
    {
        $estimate = $estimator->estimate($user, $today);
        $estimator->forget($user);
        if ($estimate === null) {
            return null;
        }

        $vdot = $estimate['vdot'];
        $distance = $estimate['race_distance_m'] ?? VdotEstimator::DEFAULT_RACE_METERS;
        $baseline = ['last_notified_vdot' => $vdot, 'last_notified_race_m' => (int) round($distance)];
        $last = $user->last_notified_vdot;
        // A VDOT read at another race distance moves with the fall-off, not with fitness.
        if ($last === null || $user->last_notified_race_m !== $baseline['last_notified_race_m']) {
            User::query()->whereKey($user->id)->update($baseline);

            return 'seeded';
        }

        if (round($vdot - $last, 1) < self::IMPROVEMENT_THRESHOLD_VDOT) {
            return null;
        }

        if ($this->notedRecently($user, $today)) {
            return 'held';
        }

        $user->notify(new FitnessImprovedNotification(
            $distance,
            (int) round($estimator->raceTimeForVdot($vdot, $distance) ?? 0),
            (int) round($estimator->raceTimeForVdot($last, $distance) ?? 0),
            $estimate['distance_m'] ?? (int) round($distance),
            $estimate['set_at']->toDateString(),
            $today->toDateString(),
        ));
        User::query()->whereKey($user->id)->update($baseline);

        return 'sent';
    }

    private function notedRecently(User $user, Carbon $today): bool
    {
        return InboxNotification::query()
            ->where('user_id', $user->id)
            ->where('kind', NotificationKind::FitnessImproved)
            ->where('created_at', '>=', $today->copy()->subDays(self::NOTE_INTERVAL_DAYS - 1))
            ->exists();
    }
}
