<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;

/**
 * A season-wide read model of the periodizer's own arc: every week from
 * {@see Season::$starts_at} to {@see Season::$ends_at}, each with its phase,
 * a planned volume figure, and (where a {@see WeeklySnapshot} already
 * exists) the actual volume that week logged. Powers the Plan tab's
 * phase-progress bar and week-by-week timeline — see
 * `docs/features/plan-periodizer.md`.
 *
 * `planned_km` is computed the same way {@see SeasonService::generateGoals()}
 * sizes its `SeasonGoal` targets: a deterministic, season-start-anchored
 * schedule ({@see PhaseSchedule}/{@see WeekPlanBuilder}/{@see SegmentGenerator}),
 * not a read of materialized {@see \App\Models\PlannedSession} rows. Those
 * rows only ever cover a rolling ~12-week horizon (see
 * `Periodizer::HORIZON_WEEKS`) and get deleted/recreated by every weekly
 * regeneration, so they're the wrong source for a stable, whole-season
 * figure — the same reasoning that already keeps `SeasonGoal` targets off
 * of them. This does mean a week's `planned_km` won't reflect a real-time
 * adaptation (e.g. an in-week deload) the way the day-by-day schedule below
 * it on the page does; that's an accepted trade-off already made for this
 * exact page's season goals.
 */
final readonly class SeasonSummaryBuilder
{
    public function __construct(
        private TrainingBaseline $baseline,
        private PhaseSchedule $phaseSchedule,
        private WeekPlanBuilder $weekPlanBuilder,
    ) {
    }

    /**
     * The season's adherence: the mean compliance score across every scored
     * {@see \App\Models\PlannedSession} inside it. Read from the persisted
     * scores `plan:score-compliance` writes, so it covers the whole season
     * rather than only the weeks the Plan page happens to render.
     */
    public function adherencePct(User $user, Season $season): ?int
    {
        $average = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$season->starts_at->toDateString(), $season->ends_at->toDateString()])
            ->whereNotNull('compliance_score')
            ->avg('compliance_score');

        return $average === null ? null : (int) round(min(100.0, (float) $average));
    }

    /**
     * @return list<array{week_start: string, phase: string, type: string, planned_km: float, actual_km: float|null, sessions: int}>
     */
    public function build(User $user, Season $season, Carbon $today): array
    {
        $race = $season->raceGoal;
        $isSelfScaled = $race === null;

        if ($race !== null) {
            $raceDistanceM = (float) $race->distance_m;
            $weeks = $this->phaseSchedule->forRace($season->starts_at, $race->race_date, $raceDistanceM);
        } else {
            $raceDistanceM = null;
            $totalWeeks = max(1, (int) $season->starts_at->diffInWeeks($season->ends_at) + 1);
            $weeks = $this->phaseSchedule->selfScaled($season->starts_at, $totalWeeks);
        }

        $phases = array_map(fn (array $w): PlanPhase => $w['phase'], $weeks);
        $multipliers = PhaseSchedule::volumeMultipliers($phases);
        $baselineData = $this->baseline->forUser($user, $season->starts_at);

        $weekEndings = array_map(
            fn (array $w): string => $w['week_start']->copy()->addDays(6)->toDateString(),
            $weeks,
        );
        $actualKmByWeekEnding = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->whereIn('week_ending', $weekEndings)
            ->get(['week_ending', 'distance_km'])
            ->mapWithKeys(fn (WeeklySnapshot $s): array => [$s->week_ending->toDateString() => (float) $s->distance_km])
            ->all();

        $currentWeekKey = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

        $result = [];
        foreach ($weeks as $i => $week) {
            $weekStart = $week['week_start'];
            $weekStartKey = $weekStart->toDateString();
            $weekEndingKey = $weekEndings[$i];
            $phase = $week['phase'];
            $multiplier = $multipliers[$i];

            $dayRows = $this->weekPlanBuilder->build($weekStart, $phase, $baselineData['sessions_per_week'], [], $raceDistanceM, $isSelfScaled);
            $primaryEasyDate = self::primaryEasyDate($dayRows);

            $plannedKm = 0.0;
            $sessions = 0;
            foreach ($dayRows as $date => $row) {
                $plannedKm += SegmentGenerator::coreKmFor(
                    $row['session_type'],
                    $date === $primaryEasyDate,
                    $baselineData['long_run_km'],
                    $multiplier,
                );
                if ($row['session_type'] !== SessionType::Rest) {
                    $sessions++;
                }
            }

            $result[] = [
                'week_start' => $weekStartKey,
                'phase' => $phase->value,
                'type' => $weekStartKey < $currentWeekKey ? 'history' : ($weekStartKey === $currentWeekKey ? 'current' : 'lookahead'),
                'planned_km' => round($plannedKm, 1),
                'actual_km' => $actualKmByWeekEnding[$weekEndingKey] ?? null,
                'sessions' => $sessions,
            ];
        }

        return $result;
    }

    /**
     * The week's first (date-order) Easy day — mirrors {@see PlanRenderer::primaryEasyDate()}
     * against a pure `WeekPlanBuilder::build()` result rather than stored rows.
     *
     * @param array<string, array{session_type: SessionType, phase: PlanPhase}> $dayRows
     */
    private static function primaryEasyDate(array $dayRows): ?string
    {
        ksort($dayRows);
        foreach ($dayRows as $date => $row) {
            if ($row['session_type'] === SessionType::Easy) {
                return $date;
            }
        }

        return null;
    }
}
