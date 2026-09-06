<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The athlete's own recent behavior, read fresh every time it's asked for
 * (generation and render both call this independently, per the periodizer's
 * "not frozen into the row" design — see `docs/features/plan-periodizer.md`).
 *
 * Prescribes a frequency and volume the athlete already has, rather than
 * inventing one: session count from the trailing 6-week average run count
 * (clamped 3-6), weekly volume from a trimmed mean of those weeks, and the
 * long run derived as a share of that volume rather than read off the single
 * longest recent run.
 *
 * The direction matters. Anchoring on the longest run lets one outlier — a
 * race, an event, a one-off adventure — set every session in the plan, since
 * {@see SegmentGenerator::coreKmFor()} scales the whole week off that one
 * scalar. Anchoring on a trimmed weekly mean describes the athlete instead of
 * their biggest day. See docs/decisions/plan-volume-anchors-on-weekly-mean.md.
 *
 * An explicit {@see TrainingPreference} sits above both: a set
 * `sessions_per_week` always wins over the behavioral average (this is the
 * one fallback stack member with no clamp of its own — the caller already
 * validated it against `WeekPlanBuilder`'s supported range). With no logged
 * weeks *and* no preference, `experience_level` picks which cold-start
 * default to seed rather than every brand-new athlete getting the same flat
 * numbers regardless of what they claim; real behavior still wins the
 * moment any exists.
 */
final class TrainingBaseline
{
    private const int TRAILING_WEEKS = 6;

    /** Below this many logged weeks there is nothing to trim, so the plain mean stands. */
    private const int MIN_WEEKS_TO_TRIM = 3;

    private const int MIN_SESSIONS_PER_WEEK = 3;

    private const int MAX_SESSIONS_PER_WEEK = 6;

    /** Floor for a brand-new athlete with no logged weeks and no stated experience level. */
    private const float DEFAULT_WEEKLY_VOLUME_KM = 15.0;

    /**
     * Cold-start `[sessions_per_week, weekly_volume_km]` seed by self-reported
     * experience, used only when the athlete has zero logged weeks.
     *
     * @var array<string, array{0: int, 1: float}>
     */
    private const array EXPERIENCE_SEED = [
        'new_to_running' => [3, 8.0],
        'returning' => [4, 14.0],
        'experienced' => [5, 22.0],
    ];

    /**
     * Long-run share of weekly volume, as `[volume_below_km, share]` ascending,
     * with the flat high-volume share past the last band. Daniels caps a
     * long run at 25% of weekly mileage, Pfitzinger at 25-30%; both ranges
     * assume higher mileage than a beginner runs, and 25% of 20 km/week is not
     * a long run at all, so the share rises as volume falls.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const array LONG_RUN_SHARE_BANDS = [
        [30.0, 0.35],
        [60.0, 0.30],
    ];

    private const float LONG_RUN_SHARE_ABOVE_BANDS = 0.25;

    /**
     * Long-run ceiling in km, as `[race_distance_below_m, cap_km]` ascending,
     * with the marathon cap past the last band. The long-run to
     * race-distance ratio *inverts* with distance — a 5K long run is 2-3x race
     * distance, a marathon's is 0.7-0.85x — so a single multiplier is wrong at
     * both ends and this has to be a table.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const array LONG_RUN_CAP_BANDS = [
        [6000.0, 16.0],
        [12000.0, 20.0],
        [25000.0, 22.0],
    ];

    private const float LONG_RUN_CAP_MARATHON_KM = 35.0;

    /**
     * With no race goal the season is self-scaled, so there is no distance to
     * band against. The half-marathon cap stands in: it is generous enough that
     * the volume share below it almost always binds first (0.25 x 80 km/week is
     * still only 20 km), and it keeps a runaway volume figure bounded.
     */
    private const float LONG_RUN_CAP_NO_RACE_KM = 22.0;

    /**
     * The real ceiling on a long run is time on feet, not distance: a slower
     * runner covering 24 km accrues far more fatigue than a fast one. Daniels
     * caps at 2.5 hours for exactly this reason. Skipped when the athlete has
     * no VDOT yet, since there is then no pace to convert against.
     */
    private const int LONG_RUN_CAP_MINUTES = 150;

    private const float MIN_LONG_RUN_KM = 3.0;

    public function __construct(
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
    }

    /**
     * @return array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float}
     */
    public function forUser(User $user, Carbon $asOf): array
    {
        $preference = TrainingPreference::query()->where('user_id', $user->id)->first();
        $preferredSessions = $preference?->sessions_per_week;
        $experienceLevel = $preference?->experience_level;

        $weeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $asOf->toDateString())
            ->orderByDesc('week_ending')
            ->limit(self::TRAILING_WEEKS)
            ->get();

        $hasHistory = ! $weeks->isEmpty();
        $seed = (! $hasHistory && $experienceLevel !== null) ? self::EXPERIENCE_SEED[$experienceLevel->value] : null;

        if ($preferredSessions !== null) {
            $sessionsPerWeek = $preferredSessions;
        } elseif ($hasHistory) {
            $sessionsPerWeek = self::clampSessions((float) $weeks->avg('runs'));
        } else {
            $sessionsPerWeek = $seed[0] ?? self::MIN_SESSIONS_PER_WEEK;
        }

        $weeklyVolumeKm = $this->weeklyVolumeKm($weeks, $seed);

        return [
            'sessions_per_week' => $sessionsPerWeek,
            'weekly_volume_km' => $weeklyVolumeKm,
            'long_run_km' => $this->longRunKm($user, $weeklyVolumeKm),
        ];
    }

    private static function clampSessions(float $avgRuns): int
    {
        return max(self::MIN_SESSIONS_PER_WEEK, min(self::MAX_SESSIONS_PER_WEEK, (int) round($avgRuns)));
    }

    /**
     * A trimmed mean — drop the highest and lowest week, average the rest —
     * so one 44 km week and one injured week neither of them typical cannot
     * move the anchor. Still tracks a genuine ramp, where a median would lag.
     *
     * @param  Collection<int, WeeklySnapshot>  $weeks
     * @param  array{0: int, 1: float}|null  $seed
     */
    private function weeklyVolumeKm(Collection $weeks, ?array $seed): float
    {
        $volumes = $weeks->map(fn (WeeklySnapshot $week): float => (float) $week->distance_km)->values();

        $mean = match (true) {
            $volumes->isEmpty() => 0.0,
            $volumes->count() < self::MIN_WEEKS_TO_TRIM => (float) $volumes->avg(),
            default => self::trimmedMean($volumes),
        };

        return $mean > 0.0 ? $mean : ($seed[1] ?? self::DEFAULT_WEEKLY_VOLUME_KM);
    }

    /** @param  Collection<int, float>  $volumes */
    private static function trimmedMean(Collection $volumes): float
    {
        $sorted = $volumes->sort()->values();

        return (float) $sorted->slice(1, $sorted->count() - 2)->avg();
    }

    /**
     * Volume decides the long run, not the other way round. Both ceilings are
     * applied and the tighter one wins: a race-distance band, and time on feet.
     */
    private function longRunKm(User $user, float $weeklyVolumeKm): float
    {
        $derived = $weeklyVolumeKm * self::longRunShare($weeklyVolumeKm);

        $capped = min($derived, $this->raceBandCapKm($user), $this->timeCapKm($user));

        return max(round($capped, 1), self::MIN_LONG_RUN_KM);
    }

    private static function longRunShare(float $weeklyVolumeKm): float
    {
        foreach (self::LONG_RUN_SHARE_BANDS as [$below, $share]) {
            if ($weeklyVolumeKm < $below) {
                return $share;
            }
        }

        return self::LONG_RUN_SHARE_ABOVE_BANDS;
    }

    private function raceBandCapKm(User $user): float
    {
        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

        if ($race === null) {
            return self::LONG_RUN_CAP_NO_RACE_KM;
        }

        foreach (self::LONG_RUN_CAP_BANDS as [$below, $cap]) {
            if ((float) $race->distance_m < $below) {
                return $cap;
            }
        }

        return self::LONG_RUN_CAP_MARATHON_KM;
    }

    /** INF when the athlete has no VDOT estimate, so only the band cap applies. */
    private function timeCapKm(User $user): float
    {
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user));

        if ($paces === null) {
            return INF;
        }

        return self::LONG_RUN_CAP_MINUTES * 60 / $paces['easy'];
    }
}
