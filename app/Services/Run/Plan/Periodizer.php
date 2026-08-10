<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
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
 */
final readonly class Periodizer
{
    /**
     * How many weeks ahead get materialized as rows. A race-oriented arc may
     * resolve to fewer weeks (it never plans past race day); self-scaled mode
     * always fills the full horizon, since it has no natural end.
     */
    public const int HORIZON_WEEKS = 12;

    public function __construct(
        private TrainingBaseline $baseline,
        private PhaseSchedule $phaseSchedule,
        private WeekPlanBuilder $weekPlanBuilder,
        private SeasonService $seasonService,
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
        $sessionsPerWeek = $this->baseline->forUser($user, $today)['sessions_per_week'];

        $weeks = $race !== null
            ? array_slice($this->phaseSchedule->forRace($today, $race->race_date, (float) $race->distance_m), 0, self::HORIZON_WEEKS)
            : $this->phaseSchedule->selfScaled($today, self::HORIZON_WEEKS);

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
            );
            foreach ($weekRows as $date => $row) {
                $rows[$date] = $row;
            }
        }

        DB::transaction(function () use ($user, $today, $deleteHorizonEnd, $rows): void {
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
                        'distance_band' => $row['distance_band'],
                        'pace_band' => $row['pace_band'],
                        'pinned' => false,
                        'status' => PlannedSessionStatus::Planned,
                    ],
                );
            }
        });
    }
}
