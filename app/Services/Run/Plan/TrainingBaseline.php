<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveRecentLongestRunAction;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Actions\Run\Plan\ResolveSeasonAction;

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
 * That trailing mean SETS the volume once, at the start of a {@see Season},
 * and is then frozen on the row: re-reading it every week made the ramp
 * chase its own output and made a missed week shrink the next one. Every
 * caller here reads the anchor of the season covering `$asOf`, so
 * generation, render and a late compliance verdict all agree. See
 * docs/decisions/the-arc-is-anchored-once.md.
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

    private const int RECENT_RUN_WINDOW_DAYS = 30;

    private const float MAX_RECENT_LONG_RUN_INCREASE = 1.1;

    /**
     * Below the marathon threshold the long run should reach the race
     * distance itself at some point in the arc. The volume share alone never
     * gets there for a low-mileage athlete — 0.35 x 26 km/week is a 9 km long
     * run for a 10K — and going to the start line having never covered the
     * distance is the one thing a plan with a date on it must not allow. A
     * marathon is exempt in the other direction: 0.7-0.85x race distance is
     * the coaching, and {@see self::LONG_RUN_CAP_BANDS} already says so.
     */
    private const float RACE_DISTANCE_FLOOR_THRESHOLD_M = 25_000.0;

    /**
     * The long run may never be more than half the week, whatever the floor
     * asks for. A single day carrying more than that is not a week with a long
     * run in it, it is a long run with some jogging attached.
     */
    private const float MAX_LONG_RUN_SHARE_OF_WEEK = 0.5;

    /**
     * The long run a race distance wants the athlete to reach by the block's
     * peak, as `[distance_up_to_m, km]` ascending, with the marathon target
     * past the last band. Bounded by the athlete's own `long_run_cap_km`.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const array READINESS_LONG_RUN_BANDS = [
        [15_000.0, 12.0],
        [25_000.0, 18.0],
    ];

    private const float READINESS_LONG_RUN_MARATHON_KM = 30.0;

    /** Sized against this baseline so {@see SegmentGenerator::coreKmFor()}'s 0.1 km rounding stays negligible. */
    private const float REFERENCE_BASELINE_KM = 100.0;

    /** How far back the volume floor averages the athlete's actual weeks. */
    private const int VOLUME_FLOOR_WEEKS = 12;

    public function __construct(
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        private readonly PhaseSchedule $phaseSchedule,
        private readonly ResolveActiveRaceAction $activeRace,
        private readonly ResolveTrainingPreferenceAction $trainingPreference,
        private readonly ResolveTrailingWeeksAction $weeklySnapshots,
        private readonly ResolveRecentLongestRunAction $recentLongestRun,
        private readonly ResolveSeasonAction $season,
        private readonly WeekPlanBuilder $weekPlanBuilder,
    ) {
    }

    /**
     * The race block's weeks, keyed on every input {@see self::buildBlock()}
     * reads. Bound `scoped()` in AppServiceProvider so the memo survives
     * across the season summary, compliance scoring and plan render entry
     * points within one request.
     *
     * @var array<string, list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}>>
     */
    private array $blockMemo = [];

    /**
     * The block's volume floor, keyed on the block key plus the season's
     * `volume_floor_km` and `sessionsPerWeek` — the only other inputs
     * {@see self::computeVolumeFloorKm()} reads, since that alone decides how
     * {@see WeekPlanBuilder} lays each week out.
     *
     * @var array<string, float>
     */
    private array $volumeFloorMemo = [];

    /**
     * @return array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, self_scaled: bool}
     */
    public function forUser(User $user, Carbon $asOf): array
    {
        $preference = ($this->trainingPreference)($user->id);
        $preferredSessions = $preference?->sessions_per_week;

        $weeks = $this->trailingWeeks($user, $asOf);

        $hasHistory = ! $weeks->isEmpty();
        $seed = self::seedFor($preference, $weeks);

        if ($preferredSessions !== null) {
            $sessionsPerWeek = $preferredSessions;
        } elseif ($hasHistory) {
            $sessionsPerWeek = self::clampSessions((float) $weeks->avg('runs'));
        } else {
            $sessionsPerWeek = $seed[0] ?? self::MIN_SESSIONS_PER_WEEK;
        }

        $season = $this->seasonFor($user, $asOf);
        $weeklyVolumeKm = $season->anchor_weekly_volume_km ?? $this->weeklyVolumeKm($weeks, $seed);

        $race = ($this->activeRace)($user->id);
        $longRunCapKm = $this->longRunCapKm($race, max($weeklyVolumeKm, $race === null ? 0.0 : (float) $season?->volume_floor_km), $user, $asOf);

        return [
            'sessions_per_week' => $sessionsPerWeek,
            'weekly_volume_km' => $weeklyVolumeKm,
            'long_run_km' => $this->longRunKm($race, $weeklyVolumeKm, $season, $longRunCapKm, $sessionsPerWeek),
            'long_run_cap_km' => $longRunCapKm,
            'long_run_progression_cap_km' => $this->recentLongRunCapKm($user, $asOf),
            'self_scaled' => $race === null,
        ];
    }

    /**
     * The live trailing read, ignoring whatever the season has frozen — the
     * value {@see SeasonService} anchors a new arc to, and the one it
     * re-anchors against when the athlete's own volume has collapsed away
     * from it.
     */
    public function trailingWeeklyVolumeKm(User $user, Carbon $asOf): float
    {
        $preference = ($this->trainingPreference)($user->id);
        $weeks = $this->trailingWeeks($user, $asOf);

        return $this->weeklyVolumeKm($weeks, self::seedFor($preference, $weeks));
    }

    /**
     * The plain mean of the athlete's last twelve logged weeks — what they
     * actually run, and the level a race block may not prescribe below.
     * Null with no logged weeks at all.
     */
    public function recentWeeklyMeanKm(User $user, Carbon $asOf): ?float
    {
        $weeks = ($this->weeklySnapshots)($user->id, $asOf->toDateString(), self::VOLUME_FLOOR_WEEKS);

        return $weeks->isEmpty() ? null : round((float) $weeks->avg(fn (WeeklySnapshot $week): float => (float) $week->distance_km), 2);
    }

    /**
     * The arc covering `$asOf`. The latest season *starting* on or before the
     * date wins, so a day is always measured against the arc it was actually
     * prescribed under, which is what keeps a late compliance verdict honest.
     */
    private function seasonFor(User $user, Carbon $asOf): ?Season
    {
        return $this->season->currentAsOf($user->id, $asOf);
    }

    /**
     * @param  Collection<int, WeeklySnapshot>  $weeks
     * @return array{int, float}|null
     */
    private static function seedFor(?TrainingPreference $preference, Collection $weeks): ?array
    {
        $experienceLevel = $preference?->experience_level;

        return ($weeks->isEmpty() && $experienceLevel !== null) ? self::EXPERIENCE_SEED[$experienceLevel->value] : null;
    }

    /** @return Collection<int, WeeklySnapshot> */
    private function trailingWeeks(User $user, Carbon $asOf): Collection
    {
        return ($this->weeklySnapshots)($user->id, $asOf->toDateString(), self::TRAILING_WEEKS);
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
     * Volume decides the long run, not the other way round, and a race block
     * raises it only as far as its three floors ask. A block whose increases
     * are held takes the volume floor alone, over its flat curve.
     */
    private function longRunKm(?RaceGoal $race, float $weeklyVolumeKm, ?Season $season, float $capKm, int $sessionsPerWeek): float
    {
        $block = $race !== null && $season !== null ? $this->block($race, $season) : null;

        $derived = max(
            $weeklyVolumeKm * self::longRunShare($weeklyVolumeKm),
            $block === null || $season->increases_held ? 0.0 : self::longRunTargetFloorKm($race, $block, $capKm),
            $block === null ? 0.0 : $this->volumeFloorKm($race, $block, $season, $sessionsPerWeek),
        );

        return max(round(min($derived, $capKm), 1), self::MIN_LONG_RUN_KM);
    }

    /**
     * The ceiling on any single long run, whichever of the three binds
     * tightest: the race-distance band, time on feet, and half the week —
     * the week a race season's volume floor asks for, when that is the
     * bigger one.
     * Returned by {@see self::forUser()} because capping the baseline alone
     * left {@see SegmentGenerator::coreKmFor()}'s volume-multiplied
     * prescription unbounded.
     */
    private function longRunCapKm(?RaceGoal $race, float $weeklyVolumeKm, User $user, Carbon $asOf): float
    {
        return max(self::MIN_LONG_RUN_KM, min(
            self::raceBandCapKm($race),
            $this->timeCapKm($user, $asOf),
            $weeklyVolumeKm * self::MAX_LONG_RUN_SHARE_OF_WEEK,
        ));
    }

    private function recentLongRunCapKm(User $user, Carbon $asOf): float
    {
        $longestDistanceM = ($this->recentLongestRun)($user->id, $asOf, self::RECENT_RUN_WINDOW_DAYS);

        if ($longestDistanceM === null) {
            return INF;
        }

        return max(self::MIN_LONG_RUN_KM, round($longestDistanceM / 1000 * self::MAX_RECENT_LONG_RUN_INCREASE, 1));
    }

    /**
     * The race block's own weeks with the multiplier each earns, counted from
     * the season's start so the block sits where the arc puts it.
     *
     * @return list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}>
     */
    private function block(RaceGoal $race, Season $season): array
    {
        $key = self::blockKey($race, $season);

        return $this->blockMemo[$key] ??= $this->buildBlock($race, $season);
    }

    private static function blockKey(RaceGoal $race, Season $season): string
    {
        return implode('|', [
            $race->id,
            $race->race_date->toDateString(),
            $race->distance_m,
            $season->id,
            $season->starts_at->toDateString(),
            $season->increases_held ? '1' : '0',
        ]);
    }

    /** @return list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}> */
    private function buildBlock(RaceGoal $race, Season $season): array
    {
        $weeks = $this->phaseSchedule->forRace($season->starts_at, $race->race_date, (float) $race->distance_m);
        $zones = array_column($weeks, 'zone');
        $multipliers = PhaseSchedule::volumeMultipliers(array_column($weeks, 'phase'), $season->increases_held, $zones);

        $block = [];
        foreach ($weeks as $i => $week) {
            if ($zones[$i] === PhaseSchedule::ZONE_BLOCK) {
                $block[] = ['week_start' => $week['week_start'], 'phase' => $week['phase'], 'multiplier' => $multipliers[$i]];
            }
        }

        return $block;
    }

    /**
     * The baseline the block has to start from for its long run to REACH its
     * target at the block's own peak: the race distance itself below the
     * marathon threshold, and the readiness distance for every race, capped by
     * the athlete's own ceiling. Divided by the biggest Build or Peak
     * multiplier, so the athlete arrives there by running the ramp rather
     * than being handed the distance in week one. Taper and Deload are
     * reductions and never count as the ramp's high-water mark; a block
     * holding neither phase gets no floor.
     *
     * @param  list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}>  $block
     */
    private static function longRunTargetFloorKm(RaceGoal $race, array $block, float $capKm): float
    {
        $rampMultipliers = [];
        foreach ($block as $week) {
            if (in_array($week['phase'], [PlanPhase::Build, PlanPhase::Peak], true)) {
                $rampMultipliers[] = $week['multiplier'];
            }
        }
        if ($rampMultipliers === []) {
            return 0.0;
        }

        $distanceM = (float) $race->distance_m;
        $targetKm = max(
            $distanceM < self::RACE_DISTANCE_FLOOR_THRESHOLD_M ? $distanceM / 1000.0 : 0.0,
            min(self::readinessLongRunKm($distanceM), $capKm),
        );

        return ceil($targetKm / max($rampMultipliers) * 10) / 10;
    }

    /**
     * The baseline at which the block's weeks, as {@see WeekPlanBuilder} lays
     * them out and {@see SegmentGenerator::coreKmFor()} sizes them, average
     * the season's volume floor. Every session scales linearly off the
     * baseline except race day, which is the race distance whatever the
     * baseline, so the floor solves in one step. Deload and Taper weeks sit
     * under the floor; the Build and Peak weeks carry the difference.
     *
     * A block holding its increases has no ramp to carry that difference, and
     * its training weeks may not go above habit to make it up, so the floor
     * is solved over those weeks alone: each matches the athlete's mean and
     * the recovery and taper weeks dip under it.
     *
     * @param  list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}>  $block
     */
    private function volumeFloorKm(RaceGoal $race, array $block, Season $season, int $sessionsPerWeek): float
    {
        $key = self::blockKey($race, $season).'|'.($season->volume_floor_km ?? 'null').'|'.$sessionsPerWeek;

        return $this->volumeFloorMemo[$key] ??= $this->computeVolumeFloorKm($race, $block, $season, $sessionsPerWeek);
    }

    /** @param  list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}>  $block */
    private function computeVolumeFloorKm(RaceGoal $race, array $block, Season $season, int $sessionsPerWeek): float
    {
        $floorKm = $season->volume_floor_km;
        if ($season->increases_held) {
            $block = array_values(array_filter(
                $block,
                static fn (array $week): bool => ! in_array($week['phase'], [PlanPhase::Deload, PlanPhase::Taper], true),
            ));
        }
        if ($floorKm === null || $block === []) {
            return 0.0;
        }

        $raceDistanceM = (float) $race->distance_m;
        $kmPerBaselineKm = 0.0;
        $raceKm = 0.0;
        foreach ($block as $week) {
            $days = $this->weekPlanBuilder->build($week['week_start'], $week['phase'], $sessionsPerWeek, [], $raceDistanceM, false, raceDate: $race->race_date);
            ksort($days);
            $primaryEasySeen = false;
            foreach ($days as $day) {
                $isPrimaryEasy = $day['session_type'] === SessionType::Easy && ! $primaryEasySeen;
                $primaryEasySeen = $primaryEasySeen || $isPrimaryEasy;
                if ($day['session_type'] === SessionType::Race) {
                    $raceKm += SegmentGenerator::coreKmFor(SessionType::Race, false, 0.0, 1.0, INF, $raceDistanceM);

                    continue;
                }
                $kmPerBaselineKm += SegmentGenerator::coreKmFor($day['session_type'], $isPrimaryEasy, self::REFERENCE_BASELINE_KM, $week['multiplier'], INF) / self::REFERENCE_BASELINE_KM;
            }
        }

        if ($kmPerBaselineKm <= 0.0) {
            return 0.0;
        }

        return ceil(max(0.0, $floorKm * count($block) - $raceKm) / $kmPerBaselineKm * 10) / 10;
    }

    private static function readinessLongRunKm(float $raceDistanceM): float
    {
        foreach (self::READINESS_LONG_RUN_BANDS as [$upTo, $km]) {
            if ($raceDistanceM <= $upTo) {
                return $km;
            }
        }

        return self::READINESS_LONG_RUN_MARATHON_KM;
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

    private static function raceBandCapKm(?RaceGoal $race): float
    {
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
    private function timeCapKm(User $user, Carbon $asOf): float
    {
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $asOf));

        if ($paces === null) {
            return INF;
        }

        return self::LONG_RUN_CAP_MINUTES * 60 / $paces['easy'];
    }
}
