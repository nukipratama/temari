<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Enums\RaceSupport;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Gamification\SeasonRecordBuilder;
use App\Support\Numeric;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Actions\Run\Plan\ResolveSeasonAction;

/**
 * Ensures a user always has a current {@see Season}, generates its general {@see SeasonGoal} rows
 * once at creation, recounts the planned-count targets from the plan {@see self::recountPlannedGoals()},
 * and appends a race season's block goals on the first call on or after block open. Read fresh every call, mirroring {@see Periodizer}'s "a mode switch takes effect at the next
 * call" rule.
 *
 * Called from both {@see PlanPageAssembler} and {@see Periodizer::regenerate()}; the two call sites
 * are idempotent against each other, see {@see self::isCurrent()}.
 */
final readonly class SeasonService
{
    /** Self-scaled seasons match the periodizer's own materialization horizon. */
    public const int SELF_SCALED_WEEKS = Periodizer::HORIZON_WEEKS;

    /**
     * How far the athlete's own trailing volume has to fall BELOW the stored anchor before the arc is
     * re-anchored mid-season. One-directional: re-anchoring upward would compound the ramp on top of
     * its own output, so only a sustained collapse moves it.
     */
    private const float REANCHOR_COLLAPSE_FRACTION = 0.25;

    private const int FOLLOW_THROUGH_WEEKS = 6;

    /** Every week count short of the longest block, spelled out for the under-ready line. */
    private const array WEEK_COUNT_WORDS = [
        'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const string RACE_MARGIN_METRIC = 'season_race_goal_met';

    private const string PEAK_WEEKLY_KM_METRIC = 'season_peak_weekly_km';

    /** The share of the season's planned sessions of a kind that its goal asks for. */
    private const int PLANNED_GOAL_PERCENT = 80;

    private const array PLANNED_COUNT_GOALS = [
        'season_sessions_completed' => ['title' => 'Complete your planned sessions', 'unit' => 'sessions'],
        'season_quality_completed' => ['title' => 'Nail your quality sessions', 'unit' => 'sessions'],
        'season_rest_honored' => ['title' => 'Honor your rest days', 'unit' => 'days'],
    ];

    public function __construct(
        private TrainingBaseline $baseline,
        private ResolveActiveRaceAction $activeRace,
        private ResolveSeasonAction $season,
        private SeasonSummaryBuilder $seasonSummaryBuilder,
        private HydrationBacklog $hydrationBacklog,
        private SeasonRecordBuilder $records,
    ) {
    }

    public function ensureCurrent(User $user, Carbon $today): Season
    {
        try {
            return DB::transaction(function () use ($user, $today): Season {
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                $this->season->forget($user->id);
                $this->activeRace->forget($user->id);

                return $this->ensureCurrentLocked($user, $today);
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->season->forget($user->id);

            return $this->season->latest($user->id) ?? throw $e;
        }
    }

    private function ensureCurrentLocked(User $user, Carbon $today): Season
    {
        [$today, $race, $current] = $this->currentContext($user, $today);

        if ($current !== null && $this->isCurrent($current, $race, $today)) {
            $this->reanchorIfCollapsed($current, $user, $today);
            if ($race !== null) {
                $this->syncEndsAtToRaceDate($current, $race);
                $this->appendBlockGoals($current, $race, $user, $today);
            }

            return $current;
        }

        $anchorKm = $this->baseline->trailingWeeklyVolumeKm($user, $today);
        if ($current?->anchor_weekly_volume_km !== null && $this->followedPrescription($user, $today) === true) {
            $anchorKm = max($anchorKm, $current->anchor_weekly_volume_km);
        }
        $volumeFloorKm = $race !== null ? $this->baseline->recentWeeklyMeanKm($user, $today) : null;
        $increasesHeld = $race !== null && $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today);
        $endsAt = $race?->race_date->toDateString() ?? $today->copy()->addWeeks(self::SELF_SCALED_WEEKS)->toDateString();
        $blockGoalsAppendedAt = $race !== null && self::blockHasOpened($race, $today) ? Carbon::now() : null;

        // A mode switch on the day the current season started retargets that row in place:
        // `unique(user_id, starts_at)` forbids a second row for the same day.
        if ($current !== null && ! $today->isAfter($current->ends_at) && $current->starts_at->isSameDay($today)) {
            $current->update([
                'race_goal_id' => $race?->id,
                'anchor_weekly_volume_km' => $anchorKm,
                'volume_floor_km' => $volumeFloorKm,
                'increases_held' => $increasesHeld,
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

        if ($current !== null) {
            $this->recountGoals($current);
            $this->records->settle($user, $current, $today);
        }

        $season = Season::query()->create([
            'user_id' => $user->id,
            'race_goal_id' => $race?->id,
            'anchor_weekly_volume_km' => $anchorKm,
            'volume_floor_km' => $volumeFloorKm,
            'increases_held' => $increasesHeld,
            'block_goals_appended_at' => $blockGoalsAppendedAt,
            'starts_at' => $today->toDateString(),
            'ends_at' => $endsAt,
        ]);

        $this->generateGoals($season, $user, $race, $today);

        return $season;
    }

    /**
     * Sets the current season's sessions, quality and rest-day targets to
     * {@see self::PLANNED_GOAL_PERCENT} of its planned rows of each kind, and
     * removes the goal for a kind the plan never schedules. A season with no
     * plan written yet keeps the targets it opened with.
     */
    public function recountPlannedGoals(User $user): void
    {
        $season = $this->season->latest($user->id);
        if ($season instanceof Season) {
            $this->recountGoals($season);
        }
    }

    private function recountGoals(Season $season): void
    {
        $sessions = PlannedSession::query()
            ->where('user_id', $season->user_id)
            ->whereBetween('date', [$season->starts_at->toDateString(), $season->ends_at->toDateString()])
            ->get(['session_type', 'made_up_on']);
        if ($sessions->isEmpty()) {
            return;
        }

        $counts = [
            'season_sessions_completed' => $sessions->filter(fn (PlannedSession $s): bool => $s->session_type !== SessionType::Rest)->count(),
            'season_quality_completed' => $sessions->filter(fn (PlannedSession $s): bool => in_array($s->session_type, [SessionType::Tempo, SessionType::Interval, SessionType::Race], true))->count(),
            'season_rest_honored' => $sessions->filter(fn (PlannedSession $s): bool => $s->session_type === SessionType::Rest && $s->made_up_on === null)->count(),
        ];

        $existing = SeasonGoal::query()
            ->where('season_id', $season->id)
            ->whereIn('metric', array_keys(self::PLANNED_COUNT_GOALS))
            ->get()
            ->keyBy('metric');

        foreach (self::PLANNED_COUNT_GOALS as $metric => $goal) {
            $row = $existing->get($metric);
            if ($counts[$metric] === 0) {
                $row?->delete();

                continue;
            }

            $target = ceil($counts[$metric] * self::PLANNED_GOAL_PERCENT / 100);
            if ($row === null) {
                SeasonGoal::query()->create([...$goal, 'season_id' => $season->id, 'metric' => $metric, 'metric_key' => null, 'target' => $target]);
            } else {
                $row->update(['target' => $target]);
            }
        }
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
     * The anchor's only mid-season write: a season opened before the arc was anchored is backfilled
     * to where the athlete stands now; an anchored one moves only when the athlete has fallen
     * {@see self::REANCHOR_COLLAPSE_FRACTION} below it and below what the plan asked of them. A race
     * season's volume floor follows the same two rules.
     */
    private function reanchorIfCollapsed(Season $season, User $user, Carbon $today): void
    {
        $anchor = $season->anchor_weekly_volume_km;
        $trailing = $this->baseline->trailingWeeklyVolumeKm($user, $today);
        $collapsed = $anchor !== null && $trailing < $anchor * (1 - self::REANCHOR_COLLAPSE_FRACTION)
            && $this->followedPrescription($user, $today) !== true;

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
     * Whether the athlete ran at least what the plan asked of them over the
     * last completed weeks it prescribed, so a plan that eased them down never
     * reads as a collapse. Null when no settled week carries a prescription.
     */
    private function followedPrescription(User $user, Carbon $today): ?bool
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $from = $weekStart->copy()->subWeeks(self::FOLLOW_THROUGH_WEEKS);

        $prescribedKmByWeek = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $weekStart->copy()->subDay()->toDateString()])
            ->whereNotNull('prescribed_km')
            ->get(['date', 'prescribed_km'])
            ->groupBy(fn (PlannedSession $session): string => $session->date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString())
            ->map(fn ($sessions): float => Numeric::float($sessions->sum('prescribed_km')));
        if ($prescribedKmByWeek->isEmpty()) {
            return null;
        }

        $actualKm = Numeric::float(WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->whereIn('week_ending', $prescribedKmByWeek->keys()->all())
            ->sum('distance_km'));

        return $actualKm >= array_sum($prescribedKmByWeek->all()) * (1 - self::REANCHOR_COLLAPSE_FRACTION);
    }

    /**
     * Lets a held season's block ramp once the load guard can judge it. Only
     * a regeneration calls this, so the multipliers it stamps and the
     * baseline every render reads flip together.
     */
    public function releaseHeldIncreases(Season $season, User $user, Carbon $today): void
    {
        if ($season->increases_held && ! $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today)) {
            $season->update(['increases_held' => false]);
        }
    }

    /**
     * A race-oriented season ends on its race day per `docs/features/plan-periodizer.md`. Refreshes
     * `ends_at` when the race row was edited in place (same `race_goal_id`), which
     * {@see self::isCurrent()} would not notice.
     */
    private function syncEndsAtToRaceDate(Season $season, RaceGoal $race): void
    {
        if (! $season->ends_at->isSameDay($race->race_date)) {
            $season->update(['ends_at' => $race->race_date->toDateString()]);
        }
    }

    private function isCurrent(Season $season, ?RaceGoal $race, Carbon $today): bool
    {
        if ($today->isAfter($season->ends_at)) {
            return false;
        }

        return $race instanceof RaceGoal ? $season->race_goal_id === $race->id : $season->race_goal_id === null;
    }

    private function generateGoals(Season $season, User $user, ?RaceGoal $race, Carbon $today): void
    {
        $baselineData = $this->baseline->forUser($user, $today);
        $sessionsPerWeek = $baselineData['sessions_per_week'];

        $weeks = $race instanceof RaceGoal
            ? PhaseSchedule::forRace($today, $race->race_date, (float) $race->distance_m)
            : PhaseSchedule::selfScaled($today, self::SELF_SCALED_WEEKS);
        $weekCount = count($weeks);

        $phases = array_column($weeks, 'phase');
        $zones = array_column($weeks, 'zone');
        $multipliers = PhaseSchedule::volumeMultipliers($phases, !$race instanceof RaceGoal || $season->increases_held, $zones);
        $raceDistanceM = $race instanceof RaceGoal ? (float) $race->distance_m : null;

        $qualityTotal = 0;
        $longestLongRunKm = 0.0;
        foreach ($phases as $index => $phase) {
            $qualityTotal += WeekPlanBuilder::qualitySlotCount($phase, $sessionsPerWeek, $raceDistanceM, !$race instanceof RaceGoal, $zones[$index]);
            $longRunKm = SegmentGenerator::coreKmFor(SessionType::Long, isPrimaryEasy: false, longRunBaselineKm: $baselineData['long_run_km'], volumeMultiplier: $multipliers[$index], longRunCapKm: $baselineData['long_run_cap_km']);
            $longestLongRunKm = max($longestLongRunKm, $longRunKm);
        }

        $sessionsTotal = $sessionsPerWeek * $weekCount;
        $restDaysTotal = (7 - $sessionsPerWeek) * $weekCount;

        $goals = [
            [
                ...self::PLANNED_COUNT_GOALS['season_sessions_completed'],
                'metric' => 'season_sessions_completed',
                'metric_key' => null,
                'target' => (float) max(1, $sessionsTotal),
            ],
            [
                ...self::PLANNED_COUNT_GOALS['season_quality_completed'],
                'metric' => 'season_quality_completed',
                'metric_key' => null,
                'target' => (float) max(1, $qualityTotal),
            ],
            [
                'title' => 'Run this season\'s longest long run',
                'metric' => 'season_longest_long_run_km',
                'metric_key' => null,
                'target' => max(1.0, round($longestLongRunKm, 1)),
                'unit' => 'km',
            ],
            [
                ...self::PLANNED_COUNT_GOALS['season_rest_honored'],
                'metric' => 'season_rest_honored',
                'metric_key' => null,
                'target' => (float) max(1, $restDaysTotal),
            ],
        ];

        if (!$race instanceof RaceGoal) {
            $goals[] = self::consistencyGoal($season);
        } elseif (self::blockHasOpened($race, $today)) {
            $goals[] = self::raceMarginGoal();
            $goals[] = $this->peakWeeklyKmGoal($season, $race, $user);
        }

        foreach ($goals as $goal) {
            SeasonGoal::query()->firstOrCreate(
                ['season_id' => $season->id, 'metric' => $goal['metric']],
                $goal,
            );
        }
    }

    /**
     * The once-only line for a season that opened with less than a full block
     * left before race week. Stamped as it is served, so it never repeats.
     */
    public function takeUnderReadyLine(Season $season): ?string
    {
        $race = $season->raceGoal;
        if ($race === null || $season->under_ready_noted_at !== null || ! RaceSupport::forDistance((float) $race->distance_m)->dedicatedPreparation()) {
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
        return RaceSupport::forDistance((float) $race->distance_m)->dedicatedPreparation()
            && ! $today->lessThan(PhaseSchedule::blockOpensOn($race->race_date, (float) $race->distance_m));
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
            SeasonGoal::query()->firstOrCreate(
                ['season_id' => $season->id, 'metric' => self::RACE_MARGIN_METRIC],
                self::raceMarginGoal(),
            );
        }

        if (! in_array(self::PEAK_WEEKLY_KM_METRIC, $existing, true)) {
            SeasonGoal::query()->firstOrCreate(
                ['season_id' => $season->id, 'metric' => self::PEAK_WEEKLY_KM_METRIC],
                $this->peakWeeklyKmGoal($season, $race, $user),
            );
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

    /**
     * @return array{title: string, metric: string, metric_key: null, target: float, unit: string}
     */
    private static function consistencyGoal(Season $season): array
    {
        $weeks = 0;
        for ($sunday = $season->starts_at->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay(); $sunday->lte($season->ends_at); $sunday->addWeek()) {
            $weeks++;
        }

        return [
            'title' => 'Run your planned volume week by week',
            'metric' => 'season_consistent_weeks',
            'metric_key' => null,
            'target' => (float) max(1, $weeks),
            'unit' => 'weeks',
        ];
    }
}
