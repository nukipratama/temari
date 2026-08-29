<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a user always has a current {@see Season} and generates its 5
 * {@see SeasonGoal} rows once, at creation. Mirrors {@see Periodizer}'s own
 * "a mode switch takes effect at the next call" rule: this is read fresh
 * (never cached) every time it's called, and a `RaceGoal` set or cleared
 * mid-season only changes the season at the NEXT call, not retroactively.
 *
 * Called from both {@see \App\Http\Controllers\PlanController::index()} (so
 * a first-ever page view already has a season, even before any plan has been
 * regenerated) and {@see Periodizer::regenerate()} (so the weekly job and
 * on-demand regeneration keep the season in lockstep with the plan's own
 * mode). Both call sites are idempotent against each other — see
 * {@see self::isCurrent()}.
 */
final readonly class SeasonService
{
    /** Self-scaled seasons match the periodizer's own materialization horizon. */
    public const int SELF_SCALED_WEEKS = Periodizer::HORIZON_WEEKS;

    /** Rest-honored badge-board tiers, per season (see {@see \App\Actions\Gamification\GrantSeasonUnlocksAction}). */
    public const array REST_HONORED_THRESHOLDS = [3, 7];

    /** Floor so a brand-new athlete (CTL ~0) still gets a meaningful, non-zero growth target. */
    private const float MIN_CTL_GROWTH_TARGET = 3.0;

    private const float CTL_GROWTH_FRACTION = 0.10;

    public function __construct(
        private TrainingBaseline $baseline,
        private PhaseSchedule $phaseSchedule,
        private WeekPlanBuilder $weekPlanBuilder,
        private TrainingLoad $trainingLoad,
    ) {
    }

    public function ensureCurrent(User $user, ?Carbon $today = null): Season
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

        $current = Season::query()->where('user_id', $user->id)->orderByDesc('starts_at')->first();

        if ($current !== null && $this->isCurrent($current, $race, $today)) {
            return $current;
        }

        return DB::transaction(function () use ($user, $race, $today, $current): Season {
            $endsAt = $race !== null
                ? $race->race_date->toDateString()
                : $today->copy()->addWeeks(self::SELF_SCALED_WEEKS)->toDateString();

            // A mode switch on the very same day the current season started
            // (no history accumulated yet) retargets that row in place,
            // rather than closing it and opening a second row for the same
            // calendar day — which `unique(user_id, starts_at)` forbids, and
            // which would leave a nonsensical zero-day season in history.
            if ($current !== null && ! $today->isAfter($current->ends_at) && $current->starts_at->isSameDay($today)) {
                $current->update(['race_goal_id' => $race?->id, 'ends_at' => $endsAt]);
                SeasonGoal::query()->where('season_id', $current->id)->delete();
                $this->generateGoals($current, $user, $race, $today);

                return $current;
            }

            if ($current !== null && ! $today->isAfter($current->ends_at)) {
                // Still within its stored window but the mode switched (race
                // set/cleared) — close it early rather than leave it claiming
                // a window it no longer covers.
                $current->update(['ends_at' => $today->copy()->subDay()]);
            }

            $season = Season::query()->create([
                'user_id' => $user->id,
                'race_goal_id' => $race?->id,
                'starts_at' => $today->toDateString(),
                'ends_at' => $endsAt,
            ]);

            $this->generateGoals($season, $user, $race, $today);

            return $season;
        });
    }

    /**
     * The read-only counterpart to {@see self::ensureCurrent()}: returns the
     * current season if one already exists and is still valid, `null`
     * otherwise. Never creates, updates, or closes a {@see Season} row — for
     * a consumer (like the Profile page) that must not trigger the same
     * creation side effects a Plan page load does.
     */
    public function peekCurrent(User $user, ?Carbon $today = null): ?Season
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
        $current = Season::query()->where('user_id', $user->id)->orderByDesc('starts_at')->first();

        return ($current !== null && $this->isCurrent($current, $race, $today)) ? $current : null;
    }

    private function isCurrent(Season $season, ?RaceGoal $race, Carbon $today): bool
    {
        if ($today->isAfter($season->ends_at)) {
            return false;
        }

        return $race === null ? $season->race_goal_id === null : $season->race_goal_id === $race->id;
    }

    private function generateGoals(Season $season, User $user, ?RaceGoal $race, Carbon $today): void
    {
        $baselineData = $this->baseline->forUser($user, $today);
        $sessionsPerWeek = max(3, min(6, $baselineData['sessions_per_week']));

        $weeks = $race !== null
            ? $this->phaseSchedule->forRace($today, $race->race_date, (float) $race->distance_m)
            : $this->phaseSchedule->selfScaled($today, self::SELF_SCALED_WEEKS);
        $weekCount = count($weeks);

        $phases = array_map(fn (array $w): PlanPhase => $w['phase'], $weeks);
        $multipliers = PhaseSchedule::volumeMultipliers($phases);
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;

        $qualityTotal = 0;
        $longestLongRunKm = 0.0;
        foreach ($phases as $index => $phase) {
            $qualityTotal += $this->weekPlanBuilder->qualitySlotCount($phase, $sessionsPerWeek, $raceDistanceM, $race === null);
            $longRunKm = SegmentGenerator::coreKmFor(SessionType::Long, isPrimaryEasy: false, longRunBaselineKm: $baselineData['long_run_km'], volumeMultiplier: $multipliers[$index]);
            $longestLongRunKm = max($longestLongRunKm, $longRunKm);
        }

        $sessionsTotal = $sessionsPerWeek * $weekCount;
        $restDaysTotal = (7 - $sessionsPerWeek) * $weekCount;

        $goals = [
            [
                'title' => 'Complete your planned sessions',
                'metric' => 'season_sessions_completed',
                'metric_key' => null,
                'target' => (float) max(1, $sessionsTotal),
                'unit' => 'sessions',
            ],
            [
                'title' => 'Nail your quality sessions',
                'metric' => 'season_quality_completed',
                'metric_key' => null,
                'target' => (float) max(1, $qualityTotal),
                'unit' => 'sessions',
            ],
            [
                'title' => 'Run this season\'s longest long run',
                'metric' => 'season_longest_long_run_km',
                'metric_key' => null,
                'target' => max(1.0, round($longestLongRunKm, 1)),
                'unit' => 'km',
            ],
            [
                'title' => 'Honor your rest days',
                'metric' => 'season_rest_honored',
                'metric_key' => null,
                'target' => (float) max(1, $restDaysTotal),
                'unit' => 'days',
            ],
        ];

        $goals[] = $race !== null
            ? $this->raceMarginGoal()
            : $this->ctlGrowthGoal($user, $today);

        foreach ($goals as $goal) {
            SeasonGoal::query()->create([
                'season_id' => $season->id,
                ...$goal,
            ]);
        }
    }

    /**
     * @return array{title: string, metric: string, metric_key: null, target: float, unit: string}
     */
    private function raceMarginGoal(): array
    {
        $marginPct = (int) round(SeasonGamificationContext::RACE_MARGIN_FRACTION * 100);

        return [
            'title' => "Finish within {$marginPct}% of your goal time",
            'metric' => 'season_race_goal_met',
            'metric_key' => null,
            'target' => 1.0,
            'unit' => 'race',
        ];
    }

    /**
     * @return array{title: string, metric: string, metric_key: null, target: float, unit: string}
     */
    private function ctlGrowthGoal(User $user, Carbon $today): array
    {
        $summary = $this->trainingLoad->summary($user, $today);
        $startCtl = (float) ($summary['ctl_42d'] ?? 0.0);
        $target = max(self::MIN_CTL_GROWTH_TARGET, round($startCtl * self::CTL_GROWTH_FRACTION, 1));

        return [
            'title' => 'Grow your fitness (CTL) this season',
            'metric' => 'season_ctl_growth',
            'metric_key' => null,
            'target' => $target,
            'unit' => 'CTL pts',
        ];
    }
}
