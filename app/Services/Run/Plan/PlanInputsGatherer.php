<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Metrics\RecentTrainingStress;
use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The database side of a regeneration: the athlete's season, active race,
 * stated preferences, behavioral baseline, the days they have fixed or
 * already run, and how long the race projects to take them. Everything
 * {@see Periodizer} reads, gathered in one place so
 * {@see Periodizer::rowsFor()} can stay pure.
 */
final readonly class PlanInputsGatherer
{
    public function __construct(
        private TrainingBaseline $baseline,
        private SeasonService $seasonService,
        private PlanAdapter $planAdapter,
        private RiegelProjector $riegelProjector,
        private ResolveActiveRaceAction $activeRace,
        private ResolveTrainingPreferenceAction $trainingPreference,
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
        private RecentTrainingStress $trainingStress,
        private ResolveTrailingWeeksAction $trailingWeeks,
        private RaceAmbitionAssessor $ambition,
    ) {
    }

    public function forUser(User $user, Carbon $today): PlanInputs
    {
        $today = $today->copy()->startOfDay();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $horizonEnd = $currentWeekStart->copy()->addWeeks(Periodizer::HORIZON_WEEKS - 1)->addDays(6);

        // Keeps the season in lockstep with the plan's own mode: a race
        // set/cleared since the last call, or a self-scaled season's 12-week
        // expiry, both take effect here — see SeasonService's own docblock.
        $season = $this->seasonService->ensureCurrent($user, $today);
        $this->seasonService->releaseHeldIncreases($season, $user, $today);

        $race = ($this->activeRace)($user->id);
        $ambition = $race === null ? null : $this->ambition->assess($user, $race, $today);
        $preference = ($this->trainingPreference)($user->id);
        $baseline = $this->baseline->forUser($user, $today);
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $today));
        ['pinned' => $pinnedDates, 'settled' => $settledDates, 'fixed' => $fixedSessions] = $this->fixedPlanDaysIn($user, $currentWeekStart->copy()->subWeek(), $today, $horizonEnd, $baseline, $paces);
        $actualSessions = array_map(static fn (array $session): array => [
            'date' => $session['date'],
            'duration_minutes' => $session['duration_minutes'],
            'hard_minutes' => $session['non_easy_minutes'] ?? $session['lap_threshold_minutes'] ?? $session['gap_threshold_minutes'],
            'demanding' => $session['demanding'] || ($session['non_easy_minutes'] ?? 0) >= 10,
        ], $this->trainingStress->forUser($user, $today, 14)['sessions']);
        $weeks = ($this->trailingWeeks)($user->id, $currentWeekStart->copy()->subDay()->toDateString(), 6)
            ->filter(static fn (WeeklySnapshot $week): bool => $week->week_ending->gte($currentWeekStart->copy()->subWeeks(6)));

        return new PlanInputs(
            userId: $user->id,
            today: $today,
            seasonStart: $season->starts_at,
            seasonEnd: $season->ends_at,
            seasonOpensWithRecovery: $season->opens_with_recovery,
            raceDate: $race?->race_date,
            raceDistanceM: $race === null ? null : (float) $race->distance_m,
            sessionsPerWeek: $baseline['sessions_per_week'],
            runDays: $preference?->run_days,
            longRunDay: $preference?->long_run_day,
            adaptation: $this->planAdapter->forWeek($user, $currentWeekStart, $today, $race, $ambition),
            pinnedDates: $pinnedDates,
            // A day that already carries a verdict is the record of what was
            // run, not a slot left to plan. Since compliance lands at ingest
            // rather than at 00:03 the next morning, regeneration can meet a
            // settled row inside its own today-forward window — see
            // `docs/decisions/a-day-is-scored-when-it-is-run.md`.
            settledDates: $settledDates,
            // How long the race will take this athlete, not how far it is: the
            // same 10K is a VO2max event for one runner and a threshold event
            // for another, and only the projection can tell them apart.
            projectedRaceSeconds: $race === null
                ? null
                : $this->riegelProjector->project($user, (float) $race->distance_m)['predicted_sec'] ?? null,
            volumeFloorKm: $season->volume_floor_km,
            increasesHeld: $season->increases_held,
            raceGoalTimeSec: $ambition?->prescribedTimeSec(),
            paces: $paces,
            longRunBaselineKm: $baseline['long_run_km'],
            longRunCapKm: $baseline['long_run_cap_km'],
            longRunProgressionCapKm: $baseline['long_run_progression_cap_km'],
            recentPrescriptions: $this->recentPrescriptions($user, $today),
            fixedSessions: $fixedSessions,
            actualSessions: $actualSessions,
            twoRunQualityEligible: $paces !== null && $weeks->count() >= 6
                && $weeks->every(static fn (WeeklySnapshot $week): bool => $week->runs >= 2),
        );
    }

    /**
     * @param array{long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, ...} $baseline
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     * @return array{
     *     pinned: array<string, true>,
     *     settled: array<string, true>,
     *     fixed: array<string, array{session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, hard_minutes?: float|null, duration_minutes?: float, demanding?: bool}>
     * }
     *
     */
    private function fixedPlanDaysIn(User $user, Carbon $weekStart, Carbon $today, Carbon $to, array $baseline, ?array $paces): array
    {
        $rows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$weekStart->toDateString(), $to->toDateString()])
            ->where(fn (Builder $query): Builder => $query
                ->where('pinned', true)
                ->orWhere('skipped', true)
                ->orWhere('status', '!=', PlannedSessionStatus::Planned)
                ->orWhere('date', '<', $today->toDateString()))
            ->orderBy('date')
            ->get(['date', 'pinned', 'skipped', 'status', 'session_type', 'prescribed_hard_minutes', 'prescribed_pace_band', 'prescribed_pace_sec_per_km', 'phase', 'volume_multiplier', 'race_distance_m', 'clamped_km', 'rest_clamped_at', 'eased_pace_sec_per_km', 'readiness_assessment', 'prescription_race_context', 'intent_evidence']);

        $pinned = [];
        $settled = [];
        $fixed = [];
        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            if ($row->skipped || in_array($row->status, [PlannedSessionStatus::Skip, PlannedSessionStatus::Missed], true) || ($row->date->lt($today) && $row->status === PlannedSessionStatus::Planned)) {
                if (! $row->date->lt($today)) {
                    $settled[$date] = true;
                    if ($row->pinned) {
                        $pinned[$date] = true;
                    }
                }
                continue;
            }
            if ($row->date->lt($today) || $row->pinned || $row->status !== PlannedSessionStatus::Planned) {
                $fixed[$date] = $row->status->isCredited()
                    ? EffectiveSession::budgetProfileOf($row)
                    : [
                        'session_type' => $row->session_type,
                        'prescribed_hard_minutes' => $row->prescribed_hard_minutes ?? 0,
                        'prescribed_pace_band' => $row->prescribed_pace_band,
                    ];
                $budgetedType = $fixed[$date]['session_type'];
                if (! array_key_exists('hard_minutes', $fixed[$date])
                    && (($row->status !== PlannedSessionStatus::Planned && $budgetedType->isQuality())
                        || $budgetedType === SessionType::Race
                        || (in_array($budgetedType, [SessionType::Tempo, SessionType::Interval], true) && $row->prescribed_hard_minutes === null))) {
                    $fixed[$date]['hard_minutes'] = null;
                }
                if ($row->status === PlannedSessionStatus::Planned && $paces !== null) {
                    $km = SegmentGenerator::coreKmFor($row->session_type, false, $baseline['long_run_km'], (float) $row->volume_multiplier, $baseline['long_run_cap_km'], $row->race_distance_m === null ? null : (float) $row->race_distance_m, $baseline['long_run_progression_cap_km']);
                    $km = $row->clamped_km === null ? $km : min($km, (float) $row->clamped_km);
                    $prescription = new IntensityPrescription($row->prescribed_hard_minutes ?? 0, $row->prescribed_pace_band, $row->prescribed_pace_sec_per_km ?? ($row->prescribed_pace_band === null ? null : $paces[$row->prescribed_pace_band->value]), null);
                    $segments = SegmentGenerator::forPrescription($row->session_type, $row->phase, $km, $paces, $prescription);
                    $fixed[$date]['duration_minutes'] = array_sum(array_map(static fn (SessionSegment $segment): float => $segment->minutes ?? 0.0, $segments));
                    if ($row->rest_clamped_at !== null || $row->eased_pace_sec_per_km !== null) {
                        $fixed[$date] = ['session_type' => SessionType::Easy, 'prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null, 'duration_minutes' => $row->rest_clamped_at === null ? $km * $paces['easy'] / 60 : 0.0];
                    }
                }
            }
            if (! $row->date->lt($today)) {
                if ($row->pinned) {
                    $pinned[$date] = true;
                }
                if ($row->status !== PlannedSessionStatus::Planned) {
                    $settled[$date] = true;
                }
            }
        }

        return ['pinned' => $pinned, 'settled' => $settled, 'fixed' => $fixed];
    }

    /** @return array<string, array{verdict: IntentVerdict, hard_minutes: int}> */
    private function recentPrescriptions(User $user, Carbon $today): array
    {
        $rows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$today->copy()->subDays(42)->toDateString(), $today->copy()->subDay()->toDateString()])
            ->whereIn('session_type', [SessionType::Tempo, SessionType::Interval, SessionType::Long])
            ->where('intent_evidence->quality_progression', 'eligible')
            ->whereNotNull('intent_verdict')
            ->whereNotNull('prescribed_hard_minutes')
            ->where('prescribed_hard_minutes', '>', 0)
            ->latest('date')
            ->get(['session_type', 'intent_verdict', 'prescribed_hard_minutes', 'prescription_race_context']);

        $recent = [];
        foreach ($rows as $row) {
            $key = IntensityPrescriptionResolver::familyKeyForContext($row->session_type, $row->prescription_race_context);
            if (! isset($recent[$key]) && $row->intent_verdict !== null && $row->prescribed_hard_minutes !== null) {
                $recent[$key] = ['verdict' => $row->intent_verdict, 'hard_minutes' => $row->prescribed_hard_minutes];
            }
        }

        return $recent;
    }
}
