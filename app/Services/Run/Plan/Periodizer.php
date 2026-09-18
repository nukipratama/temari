<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\FeedbackSubject;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Feedback;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the deterministic periodizer: computes a phase schedule from
 * {@see PlanInputs} and writes {@see PlannedSession} rows today-forward.
 * Called weekly (`routes/console.php`) and on demand
 * ({@see \App\Http\Controllers\PlanController}).
 *
 * The database side is {@see PlanInputsGatherer}; {@see self::rowsFor()} is
 * the whole computation and touches neither.
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
 *   the gatherer always reads the CURRENT active race fresh.
 * - The plan reacts to what actually happened: {@see PlanAdapter} can turn
 *   the current week into a real {@see PlanPhase::Deload} (fewer km, no
 *   quality work) and resize every week's quality block against the race
 *   projection. Its verdict is recorded as a {@see PlanAdaptation} row so
 *   the Plan tab can explain the week it produced, including the race
 *   season's volume floor when that deload takes the week below it.
 * - Deleting a row also deletes the `plan_day` {@see Feedback} rows filed
 *   against it, and today's row carries its recorded readiness clamp
 *   ({@see RestClampRecorder}) onto the row that replaces it.
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
        private PhaseSchedule $phaseSchedule,
        private WeekPlanBuilder $weekPlanBuilder,
        private PlanInputsGatherer $gatherer,
    ) {
    }

    public function regenerate(User $user, ?Carbon $today = null): void
    {
        $this->persist($this->gatherer->forUser($user, $today ?? Carbon::today()));
    }

    /**
     * The plan itself: one row per calendar day across the horizon, keyed by
     * Y-m-d. Reads nothing and writes nothing — everything it needs is in
     * {@see PlanInputs}.
     *
     * @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float}>
     */
    public function rowsFor(PlanInputs $inputs): array
    {
        $arcStart = $inputs->arcStart();
        $arc = $inputs->raceDate !== null && $inputs->raceDistanceM !== null
            ? $this->phaseSchedule->forRace($arcStart, $inputs->raceDate, $inputs->raceDistanceM)
            // The season's own window, not a fresh horizon, so the arc
            // SeasonSummaryBuilder draws is the one the athlete trains.
            : $this->phaseSchedule->selfScaled($arcStart, max(1, (int) $arcStart->diffInWeeks($inputs->seasonEnd) + 1), $inputs->seasonOpensWithRecovery);

        $weeks = self::sliceFromCurrentWeek($arc, $arcStart, $inputs->currentWeekStart(), $inputs->adaptation['deload'], $inputs->isSelfScaled());

        $rows = [];
        foreach ($weeks as $week) {
            $weekRows = $this->weekPlanBuilder->build(
                $week['week_start'],
                $week['phase'],
                $inputs->sessionsPerWeek,
                $inputs->pinnedDates,
                $inputs->raceDistanceM,
                $inputs->isSelfScaled(),
                $inputs->today,
                $inputs->adaptation['quality_delta'],
                $inputs->runDays,
                $inputs->longRunDay,
                $inputs->projectedRaceSeconds,
                $inputs->raceDate,
                $week['zone'],
            );
            foreach ($weekRows as $date => $row) {
                $rows[$date] = [...$row, 'volume_multiplier' => $week['multiplier']];
            }
        }

        return $rows;
    }

    private function persist(PlanInputs $inputs): void
    {
        $rows = $this->rowsFor($inputs);

        DB::transaction(function () use ($inputs, $rows): void {
            // Clear the full horizon's stale unpinned rows (not just the
            // freshly-computed weeks) so a shrinking horizon — e.g. a
            // self-scaled plan's far-future weeks after the user sets a
            // near-term race — doesn't leave orphaned rows from the old mode.
            $toDelete = PlannedSession::query()
                ->where('user_id', $inputs->userId)
                ->where('pinned', false)
                ->where('status', PlannedSessionStatus::Planned)
                ->whereBetween('date', [$inputs->today->toDateString(), $inputs->horizonEnd()->toDateString()])
                ->get(['id', 'date', 'clamped_km', 'rest_clamped_at', 'eased_pace_sec_per_km']);

            // Today's row may carry a readiness clamp {@see RestClampRecorder}
            // stamped earlier the same day. The athlete was already told about
            // it, and the clamp only ever subtracts — so it survives onto the
            // row that replaces it rather than vanishing with the delete.
            $carriedClamps = $toDelete
                ->filter(fn (PlannedSession $s): bool => $s->clamped_km !== null || $s->rest_clamped_at !== null || $s->eased_pace_sec_per_km !== null)
                ->keyBy(fn (PlannedSession $s): string => $s->date->toDateString());

            PlannedSession::query()
                ->whereIn('id', $toDelete->pluck('id'))
                ->delete();

            Feedback::query()
                ->where('subject_type', FeedbackSubject::PlanDay)
                ->whereIn('subject_id', $toDelete->pluck('id'))
                ->delete();

            foreach ($rows as $date => $row) {
                if (isset($inputs->settledDates[$date])) {
                    continue;
                }

                $carriedClamp = $carriedClamps->get($date);

                PlannedSession::query()->updateOrCreate(
                    ['user_id' => $inputs->userId, 'date' => $date],
                    [
                        'phase' => $row['phase'],
                        'session_type' => $row['session_type'],
                        'volume_multiplier' => $row['volume_multiplier'],
                        // Stamped on the row so race day still knows its own
                        // distance once the goal behind it has been retired.
                        'race_distance_m' => $row['session_type'] === SessionType::Race ? (int) $inputs->raceDistanceM : null,
                        'pinned' => false,
                        'status' => PlannedSessionStatus::Planned,
                        'clamped_km' => $carriedClamp?->clamped_km,
                        'rest_clamped_at' => $carriedClamp?->rest_clamped_at,
                        'eased_pace_sec_per_km' => $carriedClamp?->eased_pace_sec_per_km,
                    ],
                );
            }

            PlanAdaptation::query()->updateOrCreate(
                ['user_id' => $inputs->userId, 'week_start' => $inputs->currentWeekStart()->toDateString()],
                [
                    'reason' => $inputs->adaptation['reason'],
                    'deload' => $inputs->adaptation['deload'],
                    'quality_delta' => $inputs->adaptation['quality_delta'],
                    'adherence_pct' => $inputs->adaptation['adherence_pct'],
                    'volume_floor_km' => self::overriddenFloorKm($inputs, $rows),
                ],
            );
        });
    }

    /**
     * The volume floor this week gives way to, recorded so the Plan tab can
     * say so: only when the adapter's deload actually turned the week down,
     * which a Taper week never is.
     *
     * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float}>  $rows
     */
    private static function overriddenFloorKm(PlanInputs $inputs, array $rows): ?float
    {
        if ($inputs->volumeFloorKm === null || ! $inputs->adaptation['deload']) {
            return null;
        }

        $weekEnd = $inputs->currentWeekStart()->addDays(6)->toDateString();
        foreach ($rows as $date => $row) {
            if ($date <= $weekEnd) {
                return $row['phase'] === PlanPhase::Deload ? $inputs->volumeFloorKm : null;
            }
        }

        return null;
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
     * @param  list<array{week_start: Carbon, phase: PlanPhase, zone: string}>  $arc
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string, multiplier: float}>
     */
    private static function sliceFromCurrentWeek(array $arc, Carbon $arcStart, Carbon $currentWeekStart, bool $deload, bool $selfScaled): array
    {
        if ($arc === []) {
            return [];
        }

        $offset = min(count($arc) - 1, max(0, (int) $arcStart->diffInWeeks($currentWeekStart)));

        $phases = array_map(static fn (array $week): PlanPhase => $week['phase'], $arc);
        if ($deload && $phases[$offset] !== PlanPhase::Taper) {
            $phases[$offset] = PlanPhase::Deload;
        }
        $multipliers = PhaseSchedule::volumeMultipliers($phases, $selfScaled, array_column($arc, 'zone'));

        $weeks = [];
        foreach (array_slice($arc, $offset, self::HORIZON_WEEKS, preserve_keys: true) as $index => $week) {
            $weeks[] = [
                'week_start' => $week['week_start'],
                'phase' => $phases[$index],
                'zone' => $week['zone'],
                'multiplier' => $multipliers[$index],
            ];
        }

        return $weeks;
    }
}
