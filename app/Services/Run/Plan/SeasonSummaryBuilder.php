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
 * of them. Future weeks therefore won't reflect a real-time adaptation; they
 * are forecasts. The current week is the exception: it takes the km, phase
 * and eased-from figure of the days the plan holds
 * ({@see CurrentWeekPlanBuilder}), so Plan's header, the season list and
 * Home's week total agree, with the arc's figure as "eased from" for a
 * reactive deload.
 */
final readonly class SeasonSummaryBuilder
{
    public function __construct(
        private TrainingBaseline $baseline,
        private CurrentWeekPlanBuilder $currentWeekPlan,
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
        $scores = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$season->starts_at->toDateString(), $season->ends_at->toDateString()])
            ->whereNotNull('compliance_score')
            ->pluck('compliance_score')
            ->map(fn (int $score): int => min(100, $score));

        return $scores->isEmpty() ? null : (int) round((float) $scores->avg());
    }

    /**
     * @return list<array{week_start: string, phase: string, zone: string, type: string, planned_km: float, eased_from_km: float|null, actual_km: float|null, sessions: int}>
     */
    public function build(User $user, Season $season, Carbon $today): array
    {
        $weeks = $this->plannedWeeks($user, $season);

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
        $seasonHasCurrentWeek = in_array($currentWeekKey, array_map(fn (array $w): string => $w['week_start']->toDateString(), $weeks), true);
        $currentWeek = $seasonHasCurrentWeek ? $this->currentWeekPlan->forUser($user, $today) : null;

        $result = [];
        foreach ($weeks as $i => $week) {
            $weekStartKey = $week['week_start']->toDateString();
            $arcKm = round($week['planned_km'], 1);
            $held = $weekStartKey === $currentWeekKey ? $currentWeek : null;

            $result[] = [
                'week_start' => $weekStartKey,
                'phase' => $held['phase'] ?? $week['phase']->value,
                'zone' => $week['zone'],
                'type' => $weekStartKey < $currentWeekKey ? 'history' : ($weekStartKey === $currentWeekKey ? 'current' : 'lookahead'),
                'planned_km' => $held['planned_km_this_week'] ?? $arcKm,
                'eased_from_km' => $held === null ? null : self::easedFromKm($held, $week['phase'], $arcKm),
                'actual_km' => $actualKmByWeekEnding[$weekEndings[$i]] ?? null,
                'sessions' => $week['sessions'],
            ];
        }

        return $result;
    }

    /**
     * The figure the current week came down from: the arc's own km when the
     * week runs as a reactive deload the arc did not schedule, else the km
     * this week's eases took off.
     *
     * @param  array{phase: string, planned_km_this_week: float, planned_km_eased_from: float|null}  $held
     */
    private static function easedFromKm(array $held, PlanPhase $arcPhase, float $arcKm): ?float
    {
        $reactiveDeload = $held['phase'] === PlanPhase::Deload->value && $arcPhase !== PlanPhase::Deload;

        return $reactiveDeload && $arcKm > $held['planned_km_this_week'] ? $arcKm : $held['planned_km_eased_from'];
    }

    /**
     * Every week of the season's arc with the km it prescribes, sized off the
     * baseline as it stood at season start.
     *
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string, planned_km: float, sessions: int}>
     */
    public function plannedWeeks(User $user, Season $season): array
    {
        $race = $season->raceGoal;
        $isSelfScaled = $race === null;

        if ($race !== null) {
            $raceDistanceM = (float) $race->distance_m;
            $weeks = PhaseSchedule::forRace($season->starts_at, $race->race_date, $raceDistanceM);
        } else {
            $raceDistanceM = null;
            $totalWeeks = max(1, (int) $season->starts_at->diffInWeeks($season->ends_at) + 1);
            $weeks = PhaseSchedule::selfScaled($season->starts_at, $totalWeeks);
        }

        $multipliers = PhaseSchedule::volumeMultipliers(array_column($weeks, 'phase'), $isSelfScaled || $season->increases_held, array_column($weeks, 'zone'));
        $baselineData = $this->baseline->forUser($user, $season->starts_at);

        $result = [];
        foreach ($weeks as $i => $week) {
            $dayRows = WeekPlanBuilder::build($week['week_start'], $week['phase'], $baselineData['sessions_per_week'], [], $raceDistanceM, $isSelfScaled, raceDate: $race?->race_date, zone: $week['zone']);
            $primaryEasyDate = self::primaryEasyDate($dayRows);

            $plannedKm = 0.0;
            $sessions = 0;
            foreach ($dayRows as $date => $row) {
                $plannedKm += SegmentGenerator::coreKmFor(
                    $row['session_type'],
                    $date === $primaryEasyDate,
                    $baselineData['long_run_km'],
                    $multipliers[$i],
                    $baselineData['long_run_cap_km'],
                    $raceDistanceM,
                    $baselineData['long_run_progression_cap_km'],
                );
                if ($row['session_type'] !== SessionType::Rest) {
                    $sessions++;
                }
            }

            $result[] = [...$week, 'planned_km' => $plannedKm, 'sessions' => $sessions];
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
