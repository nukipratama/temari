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
 * - Past dates (before `$today`) are never touched.
 * - Pinned rows are read first and never overwritten; the rest of each week
 *   is planned around them.
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
        $this->seasonService->ensureCurrent($user, $today);

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
        $preference = TrainingPreference::query()->where('user_id', $user->id)->first();
        $baselineData = $this->baseline->forUser($user, $today);
        $sessionsPerWeek = $baselineData['sessions_per_week'];

        $adaptation = $this->planAdapter->forWeek($user, $currentWeekStart, $today, $race);

        $weeks = $race !== null
            ? array_slice($this->phaseSchedule->forRace($today, $race->race_date, (float) $race->distance_m), 0, self::HORIZON_WEEKS)
            : $this->phaseSchedule->selfScaled($today, self::HORIZON_WEEKS);

        $weeks = self::applyDeload($weeks, $adaptation['deload']);

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
            );
            foreach ($weekRows as $date => $row) {
                $rows[$date] = $row;
            }
        }

        DB::transaction(function () use ($user, $today, $currentWeekStart, $deleteHorizonEnd, $rows, $adaptation, $raceDistanceM): void {
            // Clear the full horizon's stale unpinned rows (not just the
            // freshly-computed weeks) so a shrinking horizon — e.g. a
            // self-scaled plan's far-future weeks after the user sets a
            // near-term race — doesn't leave orphaned rows from the old mode.
            PlannedSession::query()
                ->where('user_id', $user->id)
                ->where('pinned', false)
                ->whereBetween('date', [$today->toDateString(), $deleteHorizonEnd->toDateString()])
                ->delete();

            foreach ($rows as $date => $row) {
                PlannedSession::query()->updateOrCreate(
                    ['user_id' => $user->id, 'date' => $date],
                    [
                        'phase' => $row['phase'],
                        'session_type' => $row['session_type'],
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
     * Turns the current week into a real deload. Taper weeks are left alone:
     * they are already a planned reduction counting down to race day, and
     * restarting the taper curve from a deload multiplier would leave the
     * athlete under-stimulated going in.
     *
     * @param  list<array{week_start: Carbon, phase: PlanPhase}>  $weeks
     * @return list<array{week_start: Carbon, phase: PlanPhase}>
     */
    private static function applyDeload(array $weeks, bool $deload): array
    {
        if (! $deload || $weeks === [] || $weeks[0]['phase'] === PlanPhase::Taper) {
            return $weeks;
        }

        $weeks[0]['phase'] = PlanPhase::Deload;

        return $weeks;
    }
}
