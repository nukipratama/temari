<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Metrics\ResolveHardEffortsAction;
use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\RaceChangeKind;
use App\Enums\RaceOutcome;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RaceGoalChange;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Metrics\RecentTrainingStress;
use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The database side of a regeneration: the athlete's season, active race,
 * stated preferences, behavioral baseline, the days they have fixed or
 * already run, and how long the race projects to take them. Everything
 * {@see Periodizer} reads, gathered in one place so
 * {@see Periodizer::rowsFor()} can stay pure.
 */
final readonly class PlanInputsGatherer
{
    private const int RESUME_TRAILING_WEEKS = 4;

    private const int RESUME_MIN_WEEKS = 2;

    public function __construct(
        private TrainingBaseline $baseline,
        private SeasonService $seasonService,
        private PlanAdapter $planAdapter,
        private ResolveActiveRaceAction $activeRace,
        private ResolveTrainingPreferenceAction $trainingPreference,
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
        private RecentTrainingStress $trainingStress,
        private ResolveTrailingWeeksAction $trailingWeeks,
        private RaceAmbitionAssessor $ambition,
        private RaceOutcomeMatcher $raceOutcomes,
        private ResolveHardEffortsAction $hardEfforts,
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
        $trialDistanceM = TimeTrial::distanceFor($race === null ? null : (float) $race->distance_m);
        $ambition = $race === null ? null : $this->ambition->assess($user, $race, $today);
        $preference = ($this->trainingPreference)($user->id);
        $baseline = $this->baseline->forUser($user, $today);
        $layout = $this->baseline->weekLayout($user, $today);
        $estimate = $this->vdotEstimator->estimate($user, $today);
        $paces = $this->paceCalculator->fromVdotResult($estimate);
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
            recovery: $this->postRaceRecovery($user, $today),
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
            // for another, and only the time the plan trains for can tell them apart.
            projectedRaceSeconds: $layout['projected_race_seconds'],
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
            resumeTrailingMeanKm: $this->resumeTrailingMeanKm($race, $weeks, $currentWeekStart),
            fallOffTilt: $layout['fall_off_tilt'],
            raceAmbitionState: $ambition?->state,
            raceAmbitionGapPct: $ambition?->gapPct,
            raceSteppingStoneTimeSec: $ambition?->steppingStoneTimeSec,
            timeTrialAimSec: $this->timeTrialAimSec($estimate, $trialDistanceM),
            timeTrials: $this->timeTrials($user, $season->starts_at->copy()->startOfWeek(Carbon::MONDAY), $today),
            timeTrialEvidenceDates: $this->timeTrialEvidenceDates($user, $trialDistanceM, $currentWeekStart),
        );
    }

    private function postRaceRecovery(User $user, Carbon $today): ?PostRaceRecovery
    {
        $races = RaceGoal::query()
            ->where('user_id', $user->id)
            ->whereBetween('race_date', [$today->copy()->subDays(14)->toDateString(), $today->toDateString()])
            ->where(static fn (Builder $query): Builder => $query->whereNull('outcome')->orWhereNotIn('outcome', [RaceOutcome::DidNotRun->value, RaceOutcome::Cancelled->value]))
            ->orderByDesc('race_date')
            ->get();

        foreach ($races as $race) {
            $distanceRunM = $race->outcome === RaceOutcome::Confirmed
                ? (float) (ActivityDetail::query()->where('activity_id', $race->outcome_activity_id)->value('distance') ?? $race->distance_m)
                : $this->raceOutcomes->candidates($race)->first()['distance_m'] ?? null;
            if ($distanceRunM !== null) {
                return PostRaceRecovery::after($race->race_date, $distanceRunM);
            }
        }

        return null;
    }

    /** @param  array{vdot: float, ...}|null  $estimate */
    private function timeTrialAimSec(?array $estimate, int $distanceM): ?int
    {
        $seconds = $estimate === null ? null : $this->vdotEstimator->raceTimeForVdot($estimate['vdot'], $distanceM);

        return $seconds === null ? null : (int) round($seconds);
    }

    /** @return list<array{date: string, retry: bool, skipped: bool}> */
    private function timeTrials(User $user, Carbon $from, Carbon $today): array
    {
        $trials = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $from->toDateString())
            ->where('prescription_race_context->kind', TimeTrial::KIND)
            ->where(fn (Builder $query): Builder => $query
                ->where('date', '<', $today->toDateString())
                ->orWhere('pinned', true)
                ->orWhere('skipped', true)
                ->orWhere('status', '!=', PlannedSessionStatus::Planned))
            ->orderBy('date')
            ->get();
        if ($trials->isEmpty()) {
            return [];
        }

        $ranOn = ActivityDetail::query()->forUser($user->id)
            ->whereBetween('activity_details.start_date_local', [$trials->first()->date->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->pluck('activity_details.start_date_local')
            ->mapWithKeys(static fn (mixed $startedAt): array => [Carbon::parse($startedAt)->toDateString() => true])
            ->all();

        return array_values($trials->map(static fn (PlannedSession $trial): array => [
            'date' => $trial->date->toDateString(),
            'retry' => (bool) ($trial->prescription_race_context['retry'] ?? 0),
            'skipped' => $trial->skipped || ($trial->date->lt($today) && TimeTrial::countsAsSkipped($trial, isset($ranOn[$trial->date->toDateString()]))),
        ])->all());
    }

    /** @return list<string> */
    private function timeTrialEvidenceDates(User $user, int $distanceM, Carbon $currentWeekStart): array
    {
        $from = $currentWeekStart->copy()->subWeeks(TimeTrialSchedule::CADENCE_WEEKS + TimeTrialSchedule::RECENT_EVIDENCE_WEEKS);
        $near = static fn (float $meters): bool => abs($meters - $distanceM) / $distanceM <= TimeTrial::DISTANCE_TOLERANCE;

        $confirmed = PerformanceEvidence::query()
            ->where('user_id', $user->id)
            ->where('performed_on', '>=', $from->toDateString())
            ->get(['distance_m', 'performed_on'])
            ->toBase()
            ->filter(static fn (PerformanceEvidence $evidence): bool => $near((float) $evidence->distance_m))
            ->map(static fn (PerformanceEvidence $evidence): string => $evidence->performed_on->toDateString());
        $efforts = collect(($this->hardEfforts)($user->id)['efforts'])
            ->filter(static fn (array $effort): bool => $effort['date']->gte($from) && $near($effort['distance_m']))
            ->map(static fn (array $effort): string => $effort['date']->toDateString());

        return array_values($confirmed->merge($efforts)->unique()->sort()->all());
    }

    /**
     * @param  Collection<int, WeeklySnapshot>  $weeks
     */
    private function resumeTrailingMeanKm(?RaceGoal $race, Collection $weeks, Carbon $currentWeekStart): ?float
    {
        $recent = $weeks->take(self::RESUME_TRAILING_WEEKS);
        if ($race === null || $recent->count() < self::RESUME_MIN_WEEKS) {
            return null;
        }

        $revised = RaceGoalChange::query()
            ->where('race_goal_id', $race->id)
            ->whereIn('kind', [RaceChangeKind::Revised, RaceChangeKind::Postponed])
            ->where('created_at', '>=', $currentWeekStart->copy()->subWeeks(self::RESUME_TRAILING_WEEKS - 1))
            ->exists();

        return $revised ? (float) $recent->avg(static fn (WeeklySnapshot $week): float => (float) $week->distance_km) : null;
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
            ->get(['date', 'pinned', 'skipped', 'status', 'session_type', 'prescribed_hard_minutes', 'prescribed_pace_band', 'prescribed_pace_sec_per_km', 'phase', 'volume_multiplier', 'race_distance_m', 'clamped_km', 'rest_clamped_at', 'eased_pace_sec_per_km', 'readiness_assessment', 'prescription_race_context', 'intent_evidence', 'fall_off_tilt']);

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
                    $km = SegmentGenerator::coreKmFor($row->session_type, false, $baseline['long_run_km'], (float) $row->volume_multiplier, $baseline['long_run_cap_km'], $row->race_distance_m === null ? null : (float) $row->race_distance_m, $baseline['long_run_progression_cap_km'], $row->fall_off_tilt, $row->prescription_race_context);
                    $km = $row->clamped_km === null ? $km : min($km, (float) $row->clamped_km);
                    $prescription = new IntensityPrescription($row->prescribed_hard_minutes ?? 0, $row->prescribed_pace_band, $row->prescribed_pace_sec_per_km ?? ($row->prescribed_pace_band === null ? null : $paces[$row->prescribed_pace_band->value]), null, $row->prescription_race_context);
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
