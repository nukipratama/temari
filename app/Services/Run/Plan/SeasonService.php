<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Enums\SessionType;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Actions\Run\Plan\ResolveSeasonAction;

/**
 * Ensures a user always has a current {@see Season}, generates its general
 * {@see SeasonGoal} rows once at creation, and appends a race season's block
 * goals on the first call on or after block open. Mirrors {@see Periodizer}'s own
 * "a mode switch takes effect at the next call" rule: this is read fresh
 * (never cached) every time it's called, and a `RaceGoal` set or cleared
 * mid-season only changes the season at the NEXT call, not retroactively.
 *
 * Called from both {@see PlanPageAssembler} (so
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

    /** Floor so a brand-new athlete (CTL ~0) still gets a meaningful, non-zero growth target. */
    private const float MIN_CTL_GROWTH_TARGET = 3.0;

    private const float CTL_GROWTH_FRACTION = 0.10;

    /**
     * How far the athlete's own trailing volume has to fall BELOW the stored
     * anchor before the arc is re-anchored to it mid-season. Deliberately
     * one-directional: a trailing mean that has RISEN is the ramp working, and
     * re-anchoring upward would compound the ramp on top of its own output —
     * which is the bug this whole anchor exists to fix. Only a collapse — an
     * injury, a layoff, a month of travel — is a reason to redescribe the
     * athlete, and a trimmed six-week mean needs a sustained one to move this
     * far, so a single down week never trips it.
     */
    private const float REANCHOR_COLLAPSE_FRACTION = 0.25;

    /** Every week count short of the longest block, spelled out for the under-ready line. */
    private const array WEEK_COUNT_WORDS = [
        'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const string RACE_MARGIN_METRIC = 'season_race_goal_met';

    private const string PEAK_WEEKLY_KM_METRIC = 'season_peak_weekly_km';

    public function __construct(
        private TrainingBaseline $baseline,
        private PhaseSchedule $phaseSchedule,
        private WeekPlanBuilder $weekPlanBuilder,
        private TrainingLoad $trainingLoad,
        private ResolveActiveRaceAction $activeRace,
        private ResolveSeasonAction $season,
        private SeasonSummaryBuilder $seasonSummaryBuilder,
    ) {
    }

    public function ensureCurrent(User $user, Carbon $today): Season
    {
        [$today, $race, $current] = $this->currentContext($user, $today);

        if ($current !== null && $this->isCurrent($current, $race, $today)) {
            $this->reanchorIfCollapsed($current, $user, $today);
            if ($race !== null) {
                $this->appendBlockGoals($current, $race, $user, $today);
            }

            return $current;
        }

        return DB::transaction(function () use ($user, $race, $today, $current): Season {
            $anchorKm = $this->baseline->trailingWeeklyVolumeKm($user, $today);
            $volumeFloorKm = $race !== null ? $this->baseline->recentWeeklyMeanKm($user, $today) : null;
            $opensWithRecovery = $race === null && self::followsARaceAlreadyRun($current, $today);
            $endsAt = $race !== null
                ? $race->race_date->toDateString()
                : $today->copy()->addWeeks(self::SELF_SCALED_WEEKS)->toDateString();
            $blockGoalsAppendedAt = $race !== null && self::blockHasOpened($race, $today) ? Carbon::now() : null;

            // A mode switch on the very same day the current season started
            // (no history accumulated yet) retargets that row in place,
            // rather than closing it and opening a second row for the same
            // calendar day — which `unique(user_id, starts_at)` forbids, and
            // which would leave a nonsensical zero-day season in history.
            if ($current !== null && ! $today->isAfter($current->ends_at) && $current->starts_at->isSameDay($today)) {
                $current->update([
                    'race_goal_id' => $race?->id,
                    'anchor_weekly_volume_km' => $anchorKm,
                    'volume_floor_km' => $volumeFloorKm,
                    'opens_with_recovery' => $opensWithRecovery,
                    'block_goals_appended_at' => $blockGoalsAppendedAt,
                    'ends_at' => $endsAt,
                ]);
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
                'anchor_weekly_volume_km' => $anchorKm,
                'volume_floor_km' => $volumeFloorKm,
                'opens_with_recovery' => $opensWithRecovery,
                'block_goals_appended_at' => $blockGoalsAppendedAt,
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
    public function peekCurrent(User $user, Carbon $today): ?Season
    {
        [$today, $race, $current] = $this->currentContext($user, $today);

        return ($current !== null && $this->isCurrent($current, $race, $today)) ? $current : null;
    }

    /**
     * @return array{0: Carbon, 1: ?RaceGoal, 2: ?Season}
     */
    private function currentContext(User $user, Carbon $today): array
    {
        $today = $today->copy()->startOfDay();
        $race = ($this->activeRace)($user->id);
        $current = $this->season->latest($user->id);

        return [$today, $race, $current];
    }

    /**
     * The anchor's only mid-season write. A season opened before the arc was
     * anchored carries nothing to ramp off and would stay flat forever, so it
     * is backfilled to where the athlete stands now; an anchored one moves
     * only when the athlete has fallen {@see self::REANCHOR_COLLAPSE_FRACTION}
     * below it. A replan, a page load or a manual regeneration reaches here
     * every time and must leave the arc alone — only a race change (which
     * opens a new season) resets it outright.
     *
     * A race season's volume floor follows the same two rules: backfilled
     * from the weeks before the season opened when it has none, and brought
     * down to the new anchor after a collapse, since the weeks it averaged no
     * longer describe the athlete.
     */
    private function reanchorIfCollapsed(Season $season, User $user, Carbon $today): void
    {
        $anchor = $season->anchor_weekly_volume_km;
        $trailing = $this->baseline->trailingWeeklyVolumeKm($user, $today);
        $collapsed = $anchor !== null && $trailing < $anchor * (1 - self::REANCHOR_COLLAPSE_FRACTION);

        if ($anchor === null || $collapsed) {
            $season->update(['anchor_weekly_volume_km' => $trailing]);
        }

        if ($season->race_goal_id === null) {
            return;
        }

        if ($collapsed) {
            $season->update(['volume_floor_km' => $trailing]);
        } elseif ($season->volume_floor_km === null) {
            $season->update(['volume_floor_km' => $this->baseline->recentWeeklyMeanKm($user, $season->starts_at)]);
        }
    }

    /**
     * Whether the arc being opened comes straight off a race the athlete
     * actually ran. `plan:close-finished-races` retires the goal the morning
     * after race day and the plan falls back to the self-scaled arc, which
     * used to open at Build x1.0 — a full training week from a runner who
     * raced on Saturday.
     *
     * Read off the race DATE rather than `completed_at`: a goal is also
     * retired when the athlete calls the race off ({@see
     * \App\Http\Controllers\RaceController::destroy()}) or supersedes it
     * with another, and there is nothing to recover from in either case.
     */
    private static function followsARaceAlreadyRun(?Season $previous, Carbon $today): bool
    {
        $race = $previous?->raceGoal;

        return $race !== null && ! $race->race_date->startOfDay()->isAfter($today);
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
        $sessionsPerWeek = $baselineData['sessions_per_week'];

        $weeks = $race !== null
            ? $this->phaseSchedule->forRace($today, $race->race_date, (float) $race->distance_m)
            : $this->phaseSchedule->selfScaled($today, self::SELF_SCALED_WEEKS, $season->opens_with_recovery);
        $weekCount = count($weeks);

        $phases = array_column($weeks, 'phase');
        $zones = array_column($weeks, 'zone');
        $multipliers = PhaseSchedule::volumeMultipliers($phases, $race === null, $zones);
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;

        $qualityTotal = 0;
        $longestLongRunKm = 0.0;
        foreach ($phases as $index => $phase) {
            $qualityTotal += $this->weekPlanBuilder->qualitySlotCount($phase, $sessionsPerWeek, $raceDistanceM, $race === null, $zones[$index]);
            $longRunKm = SegmentGenerator::coreKmFor(SessionType::Long, isPrimaryEasy: false, longRunBaselineKm: $baselineData['long_run_km'], volumeMultiplier: $multipliers[$index], longRunCapKm: $baselineData['long_run_cap_km']);
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

        if ($race === null) {
            $goals[] = $this->ctlGrowthGoal($user, $today);
        } elseif (self::blockHasOpened($race, $today)) {
            $goals[] = self::raceMarginGoal();
            $goals[] = $this->peakWeeklyKmGoal($season, $race, $user);
        }

        foreach ($goals as $goal) {
            SeasonGoal::query()->create([
                'season_id' => $season->id,
                ...$goal,
            ]);
        }
    }

    /**
     * The once-only line for a season that opened with less than a full block
     * left before race week. Stamped as it is served, so it never repeats.
     */
    public function takeUnderReadyLine(Season $season): ?string
    {
        $race = $season->raceGoal;
        if ($race === null || $season->under_ready_noted_at !== null) {
            return null;
        }

        $raceWeek = $race->race_date->copy()->startOfWeek(Carbon::MONDAY);
        $blockOpen = PhaseSchedule::blockOpensOn($race->race_date, (float) $race->distance_m);
        $weeks = (int) $season->starts_at->copy()->startOfWeek(Carbon::MONDAY)->max($blockOpen)->diffInWeeks($raceWeek) + 1;

        if ($weeks >= (int) $blockOpen->diffInWeeks($raceWeek) + 1) {
            return null;
        }

        $season->update(['under_ready_noted_at' => Carbon::now()]);

        return self::WEEK_COUNT_WORDS[$weeks - 1].' '.Str::plural('week', $weeks)
            ." is tighter than I'd pick for this one, so we build what we can and race what we've built.";
    }

    private static function blockHasOpened(RaceGoal $race, Carbon $today): bool
    {
        return ! $today->lessThan(PhaseSchedule::blockOpensOn($race->race_date, (float) $race->distance_m));
    }

    /**
     * The race-specific goals for a season opened before its block, added the
     * first time it is read on or after block open and stamped so later reads skip the database.
     */
    private function appendBlockGoals(Season $season, RaceGoal $race, User $user, Carbon $today): void
    {
        if ($season->block_goals_appended_at !== null || ! self::blockHasOpened($race, $today)) {
            return;
        }

        $existing = SeasonGoal::query()->where('season_id', $season->id)->pluck('metric')->all();

        if (! in_array(self::RACE_MARGIN_METRIC, $existing, true)) {
            SeasonGoal::query()->create(['season_id' => $season->id, ...self::raceMarginGoal()]);
        }

        if (! in_array(self::PEAK_WEEKLY_KM_METRIC, $existing, true)) {
            SeasonGoal::query()->create(['season_id' => $season->id, ...$this->peakWeeklyKmGoal($season, $race, $user)]);
        }

        $season->update(['block_goals_appended_at' => Carbon::now()]);
    }

    /**
     * @return array{title: string, metric: string, metric_key: null, target: float, unit: string}
     */
    private static function raceMarginGoal(): array
    {
        $marginPct = (int) round(SeasonGamificationContext::RACE_MARGIN_FRACTION * 100);

        return [
            'title' => "Finish within {$marginPct}% of your goal time",
            'metric' => self::RACE_MARGIN_METRIC,
            'metric_key' => null,
            'target' => 1.0,
            'unit' => 'race',
        ];
    }

    /**
     * @return array{title: string, metric: string, metric_key: null, target: float, unit: string}
     */
    private function peakWeeklyKmGoal(Season $season, RaceGoal $race, User $user): array
    {
        $season->setRelation('raceGoal', $race);
        $peakKm = 0.0;
        foreach ($this->seasonSummaryBuilder->plannedWeeks($user, $season) as $week) {
            if ($week['zone'] === PhaseSchedule::ZONE_BLOCK) {
                $peakKm = max($peakKm, $week['planned_km']);
            }
        }

        return [
            'title' => 'Run your peak training week',
            'metric' => self::PEAK_WEEKLY_KM_METRIC,
            'metric_key' => null,
            'target' => max(1.0, round($peakKm, 1)),
            'unit' => 'km',
        ];
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array{title: string, metric: string, metric_key: null, target: float, unit: string}
     */
    private function ctlGrowthGoal(User $user, Carbon $today): array
    {
        $summary = $this->trainingLoad->summary($user, $today);

        // An unscored load curve is null, never zero — see
        // docs/decisions/unscored-load-is-null-not-zero.md. Coercing it here
        // would claim the athlete has no fitness rather than that we cannot see
        // it; both land on the floor, but only one of them says something false.
        $startCtl = self::floatOrNull($summary['ctl_42d'] ?? null);
        $target = $startCtl === null
            ? self::MIN_CTL_GROWTH_TARGET
            : max(self::MIN_CTL_GROWTH_TARGET, round($startCtl * self::CTL_GROWTH_FRACTION, 1));

        return [
            'title' => 'Grow your fitness (CTL) this season',
            'metric' => 'season_ctl_growth',
            'metric_key' => null,
            'target' => $target,
            'unit' => 'CTL pts',
        ];
    }
}
