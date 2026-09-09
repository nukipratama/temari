<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Services\Run\Metrics\RiegelProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the deterministic periodizer: reads the athlete's active race
 * (if any) and their own recent behavior, computes a phase schedule, and
 * writes {@see PlannedSession} rows today-forward. Called weekly
 * (`routes/console.php`) and on demand ({@see \App\Http\Controllers\PlanController}).
 *
 * Invariants (see `docs/features/plan-periodizer.md`):
 * - The arc is counted from the {@see \App\Models\Season}'s own start, not
 *   from today, and only then sliced to the horizon — so the week being
 *   trained carries the multiplier its position in the season earns rather
 *   than the 1.0 that index 0 always earns. See
 *   `docs/decisions/the-arc-is-anchored-once.md`.
 * - Past dates (before `$today`) are never touched.
 * - Pinned rows are read first and never overwritten; the rest of each week
 *   is planned around them.
 * - A row already carrying a verdict is left alone, even inside the
 *   today-forward window: it records what was run, not what is still planned.
 * - A mode switch (race set/cleared) only takes effect at the next call —
 *   this method always reads the CURRENT active race fresh.
 * - The plan reacts to what actually happened: {@see PlanAdapter} can turn
 *   the current week into a real {@see PlanPhase::Deload} (fewer km, no
 *   quality work) and resize every week's quality block against the race
 *   projection. Its verdict is recorded as a {@see PlanAdaptation} row so
 *   the Plan tab can explain the week it produced.
 */
final readonly class Periodizer
{
    /**
     * How many weeks ahead get materialized as rows. A race-oriented arc may
     * resolve to fewer weeks — it ends with race week, and nothing after race
     * day inside that week is trained; self-scaled mode always fills the full
     * horizon, since it has no natural end.
     */
    public const int HORIZON_WEEKS = 12;

    public function __construct(
        private TrainingBaseline $baseline,
        private PhaseSchedule $phaseSchedule,
        private WeekPlanBuilder $weekPlanBuilder,
        private SeasonService $seasonService,
        private PlanAdapter $planAdapter,
        private RiegelProjector $riegelProjector,
    ) {
    }

    public function regenerate(User $user, ?Carbon $today = null): void
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $deleteHorizonEnd = $currentWeekStart->copy()->addWeeks(self::HORIZON_WEEKS - 1)->addDays(6);

        // Keeps the season in lockstep with the plan's own mode: a race
        // set/cleared since the last call, or a self-scaled season's 12-week
        // expiry, both take effect here — see SeasonService's own docblock.
        $season = $this->seasonService->ensureCurrent($user, $today);

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
        $preference = TrainingPreference::query()->where('user_id', $user->id)->first();
        $baselineData = $this->baseline->forUser($user, $today);
        $sessionsPerWeek = $baselineData['sessions_per_week'];

        $adaptation = $this->planAdapter->forWeek($user, $currentWeekStart, $today, $race);

        $arcStart = $season->starts_at->copy()->startOfWeek(Carbon::MONDAY);
        $arc = $race !== null
            ? $this->phaseSchedule->forRace($arcStart, $race->race_date, (float) $race->distance_m)
            // The season's own window, not a fresh horizon, so the arc
            // SeasonSummaryBuilder draws is the one the athlete trains.
            : $this->phaseSchedule->selfScaled($arcStart, max(1, (int) $arcStart->diffInWeeks($season->ends_at) + 1), $season->opens_with_recovery);

        $weeks = self::sliceFromCurrentWeek($arc, $arcStart, $currentWeekStart, $adaptation['deload']);

        $pinnedDates = array_fill_keys(
            PlannedSession::query()
                ->where('user_id', $user->id)
                ->where('pinned', true)
                ->whereBetween('date', [$today->toDateString(), $deleteHorizonEnd->toDateString()])
                ->pluck('date')
                ->map(fn (Carbon $date): string => $date->toDateString())
                ->all(),
            true,
        );

        // A day that already carries a verdict is the record of what was run,
        // not a slot left to plan. Since compliance lands at ingest rather
        // than at 00:03 the next morning, regeneration can meet a settled row
        // inside its own today-forward window — see
        // `docs/decisions/a-day-is-scored-when-it-is-run.md`.
        $settledDates = array_fill_keys(
            PlannedSession::query()
                ->where('user_id', $user->id)
                ->where('status', '!=', PlannedSessionStatus::Planned)
                ->whereBetween('date', [$today->toDateString(), $deleteHorizonEnd->toDateString()])
                ->pluck('date')
                ->map(fn (Carbon $date): string => $date->toDateString())
                ->all(),
            true,
        );

        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;
        // How long the race will take this athlete, not how far it is: the same
        // 10K is a VO2max event for one runner and a threshold event for
        // another, and only the projection can tell them apart.
        $projectedRaceSeconds = $race === null
            ? null
            : $this->riegelProjector->project($user, (float) $race->distance_m)['predicted_sec'] ?? null;

        $rows = [];
        foreach ($weeks as $week) {
            $weekRows = $this->weekPlanBuilder->build(
                $week['week_start'],
                $week['phase'],
                $sessionsPerWeek,
                $pinnedDates,
                $raceDistanceM,
                $race === null,
                $today,
                $adaptation['quality_delta'],
                $preference?->run_days,
                $preference?->long_run_day,
                $projectedRaceSeconds,
                $race?->race_date,
                $adaptation['reason']->keepsAQualitySession(),
            );
            foreach ($weekRows as $date => $row) {
                $rows[$date] = [...$row, 'volume_multiplier' => $week['multiplier']];
            }
        }

        DB::transaction(function () use ($user, $today, $currentWeekStart, $deleteHorizonEnd, $rows, $settledDates, $adaptation, $raceDistanceM): void {
            // Clear the full horizon's stale unpinned rows (not just the
            // freshly-computed weeks) so a shrinking horizon — e.g. a
            // self-scaled plan's far-future weeks after the user sets a
            // near-term race — doesn't leave orphaned rows from the old mode.
            PlannedSession::query()
                ->where('user_id', $user->id)
                ->where('pinned', false)
                ->where('status', PlannedSessionStatus::Planned)
                ->whereBetween('date', [$today->toDateString(), $deleteHorizonEnd->toDateString()])
                ->delete();

            foreach ($rows as $date => $row) {
                if (isset($settledDates[$date])) {
                    continue;
                }

                PlannedSession::query()->updateOrCreate(
                    ['user_id' => $user->id, 'date' => $date],
                    [
                        'phase' => $row['phase'],
                        'session_type' => $row['session_type'],
                        'volume_multiplier' => $row['volume_multiplier'],
                        // Stamped on the row so race day still knows its own
                        // distance once the goal behind it has been retired.
                        'race_distance_m' => $row['session_type'] === SessionType::Race ? (int) $raceDistanceM : null,
                        'pinned' => false,
                        'status' => PlannedSessionStatus::Planned,
                    ],
                );
            }

            PlanAdaptation::query()->updateOrCreate(
                ['user_id' => $user->id, 'week_start' => $currentWeekStart->toDateString()],
                [
                    'reason' => $adaptation['reason'],
                    'deload' => $adaptation['deload'],
                    'quality_delta' => $adaptation['quality_delta'],
                    'adherence_pct' => $adaptation['adherence_pct'],
                ],
            );
        });
    }

    /**
     * The horizon's worth of weeks starting at the current one, each carrying
     * the multiplier its position in the WHOLE arc earns. Slicing after the
     * multipliers are computed is the entire point: computing them over the
     * remaining weeks alone put the week being trained at index 0 every
     * Monday, which is 1.0 in every phase but Taper.
     *
     * The reactive deload lands before the multipliers, so a week the adapter
     * turns down is not counted as a Build week by the weeks after it. Taper
     * weeks are left alone: they are already a planned reduction counting down
     * to race day, and restarting the taper curve from a deload multiplier
     * would leave the athlete under-stimulated going in.
     *
     * A season whose stored window outlasts its own arc — only reachable for a
     * self-scaled row written before the two were aligned — holds on its last
     * arc week rather than materializing nothing at all, until it rolls over.
     *
     * @param  list<array{week_start: Carbon, phase: PlanPhase}>  $arc
     * @return list<array{week_start: Carbon, phase: PlanPhase, multiplier: float}>
     */
    private static function sliceFromCurrentWeek(array $arc, Carbon $arcStart, Carbon $currentWeekStart, bool $deload): array
    {
        if ($arc === []) {
            return [];
        }

        $offset = min(count($arc) - 1, max(0, (int) $arcStart->diffInWeeks($currentWeekStart)));

        $phases = array_map(static fn (array $week): PlanPhase => $week['phase'], $arc);
        if ($deload && $phases[$offset] !== PlanPhase::Taper) {
            $phases[$offset] = PlanPhase::Deload;
        }
        $multipliers = PhaseSchedule::volumeMultipliers($phases);

        $weeks = [];
        foreach (array_slice($arc, $offset, self::HORIZON_WEEKS, preserve_keys: true) as $index => $week) {
            $weeks[] = [
                'week_start' => $week['week_start'],
                'phase' => $phases[$index],
                'multiplier' => $multipliers[$index],
            ];
        }

        return $weeks;
    }
}
