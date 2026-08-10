<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Support\Carbon;

/**
 * The athlete's own recent behavior, read fresh every time it's asked for
 * (generation and render both call this independently, per the periodizer's
 * "not frozen into the row" design — see `docs/features/plan-periodizer.md`).
 *
 * Prescribes a frequency and volume the athlete already has, rather than
 * inventing one: session count from the trailing 4-week average run count
 * (clamped 3-6), weekly volume from the trailing 4 completed weeks'
 * distance, and a long-run reference from the single longest run in the
 * trailing 28 days.
 */
final class TrainingBaseline
{
    private const int TRAILING_WEEKS = 4;

    private const int MIN_SESSIONS_PER_WEEK = 3;

    private const int MAX_SESSIONS_PER_WEEK = 6;

    /** Floor for a brand-new athlete with no logged weeks yet. */
    private const float DEFAULT_WEEKLY_VOLUME_KM = 15.0;

    /** A long run is typically ~35% of weekly volume; used only when no run in the trailing window beats it. */
    private const float LONG_RUN_FRACTION_OF_WEEKLY_VOLUME = 0.35;

    private const float MIN_LONG_RUN_KM = 3.0;

    /**
     * @return array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float}
     */
    public function forUser(User $user, Carbon $asOf): array
    {
        $weeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $asOf->toDateString())
            ->orderByDesc('week_ending')
            ->limit(self::TRAILING_WEEKS)
            ->get();

        $sessionsPerWeek = self::clampSessions(
            $weeks->isEmpty() ? 0.0 : (float) $weeks->avg('runs'),
        );

        $avgVolumeKm = $weeks->isEmpty() ? 0.0 : (float) $weeks->avg('distance_km');
        $weeklyVolumeKm = $avgVolumeKm > 0.0 ? $avgVolumeKm : self::DEFAULT_WEEKLY_VOLUME_KM;

        $longRunKm = $this->longestRunKmInWindow($user, $asOf)
            ?? round($weeklyVolumeKm * self::LONG_RUN_FRACTION_OF_WEEKLY_VOLUME, 1);

        return [
            'sessions_per_week' => $sessionsPerWeek,
            'weekly_volume_km' => $weeklyVolumeKm,
            'long_run_km' => max($longRunKm, self::MIN_LONG_RUN_KM),
        ];
    }

    private static function clampSessions(float $avgRuns): int
    {
        return max(self::MIN_SESSIONS_PER_WEEK, min(self::MAX_SESSIONS_PER_WEEK, (int) round($avgRuns)));
    }

    private function longestRunKmInWindow(User $user, Carbon $asOf): ?float
    {
        $maxMeters = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $asOf->copy()->subDays(28)->startOfDay())
            ->where('activity_details.start_date_local', '<=', $asOf->copy()->endOfDay())
            ->max('activity_details.distance');

        return $maxMeters === null ? null : DistanceFormatter::km((float) $maxMeters);
    }
}
