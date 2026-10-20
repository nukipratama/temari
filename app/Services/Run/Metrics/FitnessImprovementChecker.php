<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\NotificationKind;
use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\FitnessImprovedNotification;
use Illuminate\Support\Carbon;

/**
 * Notes an athlete's supported race time once it has risen a meaningful step since the last note,
 * for the daily scan and for a race-goal change alike.
 */
final readonly class FitnessImprovementChecker
{
    public const float IMPROVEMENT_THRESHOLD_VDOT = 0.5;

    public const int NOTE_INTERVAL_DAYS = 7;

    public function __construct(private VdotEstimator $estimator)
    {
    }

    /**
     * @return 'seeded'|'sent'|'held'|null
     */
    public function check(User $user, Carbon $today, Carbon $checkedAt): ?string
    {
        $outcome = $this->compare($user, $today);
        // A rise held back by the weekly note interval stays unchecked, so the next run retries it.
        if ($outcome !== 'held') {
            User::query()->whereKey($user->id)->update(['fitness_checked_at' => $checkedAt]);
        }

        return $outcome;
    }

    /**
     * @return 'seeded'|'sent'|'held'|null
     */
    private function compare(User $user, Carbon $today): ?string
    {
        $estimate = $this->estimator->estimate($user, $today);
        $this->estimator->forget($user);
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
            (int) round($this->estimator->raceTimeForVdot($vdot, $distance) ?? 0),
            (int) round($this->estimator->raceTimeForVdot($last, $distance) ?? 0),
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
