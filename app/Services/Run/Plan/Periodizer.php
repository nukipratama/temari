<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\FeedbackSubject;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Models\Feedback;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    private const int REGENERATION_LOCK_SECONDS = 150;

    private const int REGENERATION_LOCK_WAIT_SECONDS = 30;

    public const int REQUEST_LOCK_WAIT_SECONDS = 2;

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
        private IntensityPrescriptionResolver $prescriptionResolver,
    ) {
    }

    public function regenerate(
        User $user,
        ?Carbon $today = null,
        int $lockWaitSeconds = self::REGENERATION_LOCK_WAIT_SECONDS,
    ): void {
        $this->withRegenerationLock($user, function () use ($user, $today): void {
            $this->regenerateWithinLock($user, $today);
        }, $lockWaitSeconds);
    }

    public function regenerateWithinLock(User $user, ?Carbon $today = null): void
    {
        $this->persist($user, $this->gatherer->forUser($user, $today ?? Carbon::today()));
    }

    public function regenerateIfChanged(User $user, ?Carbon $today = null): bool
    {
        return $this->withRegenerationLock($user, function () use ($user, $today): bool {
            $today ??= Carbon::today();
            $inputs = $this->gatherer->forUser($user, $today);
            $adaptation = PlanAdaptation::query()
                ->where('user_id', $user->id)
                ->where('week_start', $inputs->currentWeekStart()->toDateString())
                ->first();

            if ($adaptation !== null && self::adaptationMatches($adaptation, $inputs)) {
                return false;
            }

            // Ingest reconciliation can only move safety in one direction inside
            // an open week. A newly healthy reading must not erase a deload or
            // restore quality that an earlier settled verdict removed.
            if ($adaptation !== null && self::wouldRelaxSafety($adaptation, $inputs)) {
                return false;
            }

            $this->persist($user, $inputs);

            return true;
        });
    }

    private static function adaptationMatches(PlanAdaptation $adaptation, PlanInputs $inputs): bool
    {
        return $adaptation->reason === $inputs->adaptation['reason']
            && $adaptation->deload === $inputs->adaptation['deload']
            && $adaptation->quality_delta === $inputs->adaptation['quality_delta']
            && $adaptation->adherence_pct === $inputs->adaptation['adherence_pct']
            && $adaptation->stimulus_adherence_pct === $inputs->adaptation['stimulus_adherence_pct']
            && $adaptation->increases_held === $inputs->increasesHeld;
    }

    private static function wouldRelaxSafety(PlanAdaptation $adaptation, PlanInputs $inputs): bool
    {
        return ($adaptation->deload && ! $inputs->adaptation['deload'])
            || ($adaptation->quality_delta < 0 && $inputs->adaptation['quality_delta'] >= 0);
    }

    /**
     * The plan itself: one row per calendar day across the horizon, keyed by
     * Y-m-d. Reads nothing and writes nothing — everything it needs is in
     * {@see PlanInputs}.
     *
     * @return array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>
     */
    public function rowsFor(PlanInputs $inputs): array
    {
        $arcStart = $inputs->arcStart();
        $arc = $inputs->raceDate !== null && $inputs->raceDistanceM !== null
            ? $this->phaseSchedule->forRace($arcStart, $inputs->raceDate, $inputs->raceDistanceM)
            // The season's own window, not a fresh horizon, so the arc
            // SeasonSummaryBuilder draws is the one the athlete trains.
            : $this->phaseSchedule->selfScaled($arcStart, max(1, (int) $arcStart->diffInWeeks($inputs->seasonEnd) + 1), $inputs->seasonOpensWithRecovery);

        $weeks = self::sliceFromCurrentWeek($arc, $arcStart, $inputs->currentWeekStart(), $inputs->adaptation['deload'], $inputs->isSelfScaled() || $inputs->increasesHeld);

        $rows = [];
        $fixedDates = $inputs->pinnedDates + $inputs->settledDates;
        foreach ($weeks as $week) {
            $weekRows = $this->weekPlanBuilder->build(
                $week['week_start'],
                $week['phase'],
                $inputs->sessionsPerWeek,
                $fixedDates,
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
            $weekRows = $this->withIntensityPrescriptions($weekRows, $week['multiplier'], $inputs, $week['week_start']);
            foreach ($weekRows as $date => $row) {
                $rows[$date] = [...$row, 'volume_multiplier' => $week['multiplier']];
            }
        }

        return $rows;
    }

    private function persist(User $user, PlanInputs $inputs): void
    {
        $rows = $this->rowsFor($inputs);

        DB::transaction(function () use ($user, $inputs, $rows): void {
            $reran = false;
            $current = $this->sessionsInHorizon($inputs);

            if ($this->containsNewFixedSession($inputs, $current)) {
                $inputs = $this->gatherer->forUser($user, $inputs->today);
                $rows = $this->rowsFor($inputs);
                $reran = true;
                $current = $this->sessionsInHorizon($inputs);
            }

            // Clear the full horizon's stale unpinned rows (not just the
            // freshly-computed weeks) so a shrinking horizon — e.g. a
            // self-scaled plan's far-future weeks after the user sets a
            // near-term race — doesn't leave orphaned rows from the old mode.
            $toDelete = $current->filter(
                fn (PlannedSession $session): bool =>
                ! $session->pinned && $session->status === PlannedSessionStatus::Planned,
            );

            // Today's row may carry a readiness clamp {@see RestClampRecorder}
            // stamped earlier the same day. The athlete was already told about
            // it, and the clamp only ever subtracts — so it survives onto the
            // row that replaces it rather than vanishing with the delete.
            $carriedClamps = $toDelete
                ->filter(fn (PlannedSession $session): bool => $session->clamped_km !== null || $session->rest_clamped_at !== null || $session->eased_pace_sec_per_km !== null)
                ->keyBy(fn (PlannedSession $session): string => $session->date->toDateString());
            $candidateIds = $toDelete->pluck('id');

            if ($candidateIds->isNotEmpty()) {
                PlannedSession::query()
                    ->whereIn('id', $candidateIds)
                    ->where('pinned', false)
                    ->where('status', PlannedSessionStatus::Planned)
                    ->delete();

                $remainingIds = PlannedSession::query()->whereIn('id', $candidateIds)->pluck('id');
                $deletedIds = $candidateIds->diff($remainingIds);

                Feedback::query()
                    ->where('subject_type', FeedbackSubject::PlanDay)
                    ->whereIn('subject_id', $deletedIds)
                    ->delete();
            }

            $current = $this->sessionsInHorizon($inputs);
            if (! $reran && $this->containsNewFixedSession($inputs, $current)) {
                $inputs = $this->gatherer->forUser($user, $inputs->today);
                $rows = $this->rowsFor($inputs);
                $reran = true;
                $current = $this->sessionsInHorizon($inputs);
            }
            $currentByDate = $current->keyBy(fn (PlannedSession $session): string => $session->date->toDateString());

            foreach ($rows as $date => $row) {
                $existing = $currentByDate->get($date);
                if (isset($inputs->settledDates[$date])
                    || $existing?->pinned
                    || ($existing !== null && $existing->status !== PlannedSessionStatus::Planned)) {
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
                        'prescribed_hard_minutes' => $row['prescribed_hard_minutes'],
                        'prescribed_pace_band' => $row['prescribed_pace_band'],
                        'prescribed_pace_sec_per_km' => $row['prescribed_pace_sec_per_km'],
                        'prescription_reason' => $row['prescription_reason'],
                        'prescription_race_context' => $row['prescription_race_context'],
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
                    'stimulus_adherence_pct' => $inputs->adaptation['stimulus_adherence_pct'],
                    'volume_floor_km' => self::overriddenFloorKm($inputs, $rows),
                    'increases_held' => $inputs->increasesHeld,
                ],
            );
        });
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withRegenerationLock(
        User $user,
        Closure $callback,
        int $waitSeconds = self::REGENERATION_LOCK_WAIT_SECONDS,
        int $lockTtlSeconds = self::REGENERATION_LOCK_SECONDS,
    ): mixed {
        return Cache::lock("plan-reconciliation:{$user->id}", $lockTtlSeconds)
            ->block($waitSeconds, $callback);
    }

    /** @return Collection<int, PlannedSession> */
    private function sessionsInHorizon(PlanInputs $inputs): Collection
    {
        return PlannedSession::query()
            ->where('user_id', $inputs->userId)
            ->whereBetween('date', [$inputs->today->toDateString(), $inputs->horizonEnd()->toDateString()])
            ->orderBy('date')
            ->lockForUpdate()
            ->get([
                'id', 'date', 'pinned', 'status', 'session_type', 'prescribed_hard_minutes', 'prescribed_pace_band',
                'clamped_km', 'rest_clamped_at', 'eased_pace_sec_per_km',
            ]);
    }

    /** @param Collection<int, PlannedSession> $sessions */
    private function containsNewFixedSession(PlanInputs $inputs, Collection $sessions): bool
    {
        foreach ($sessions as $session) {
            $date = $session->date->toDateString();
            if (($session->pinned && ! isset($inputs->pinnedDates[$date]))
                || ($session->status !== PlannedSessionStatus::Planned && ! isset($inputs->settledDates[$date]))) {
                return true;
            }

            $fixed = $inputs->fixedSessions[$date] ?? null;
            if ($fixed !== null && ($fixed['session_type'] !== $session->session_type
                || $fixed['prescribed_hard_minutes'] !== $session->prescribed_hard_minutes
                || $fixed['prescribed_pace_band'] !== $session->prescribed_pace_band)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{phase: PlanPhase, session_type: SessionType, ...}> $rows
     * @return array<string, array{phase: PlanPhase, session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>
     */
    private function withIntensityPrescriptions(array $rows, float $multiplier, PlanInputs $inputs, Carbon $weekStart): array
    {
        $firstEasy = array_find_key($rows, static fn (array $row): bool => $row['session_type'] === SessionType::Easy);
        $kmByDate = [];
        foreach ($rows as $date => $row) {
            $kmByDate[$date] = SegmentGenerator::coreKmFor(
                $row['session_type'],
                $date === $firstEasy,
                $inputs->longRunBaselineKm,
                $multiplier,
                $inputs->longRunCapKm,
                $inputs->raceDistanceM,
                $inputs->longRunProgressionCapKm,
            );
        }

        $prescriptions = [];
        foreach ($rows as $date => $row) {
            $family = IntensityPrescriptionResolver::familyKey(
                $row['session_type'],
                $inputs->raceDistanceM,
                $inputs->raceGoalTimeSec,
            );
            $recent = $inputs->recentPrescriptions[$family] ?? null;
            $prescriptions[$date] = $this->prescriptionResolver->resolve(
                $row['session_type'],
                $row['phase'],
                $inputs->raceDistanceM,
                $inputs->raceGoalTimeSec,
                $inputs->paces,
                $recent['verdict'] ?? null,
                $recent['hard_minutes'] ?? null,
            );
        }

        // Without VDOT there is no trustworthy time denominator. Keep the
        // phase/day caps, but do not invent a weekly percentage ceiling.
        $hardCeiling = $this->hardCeiling($rows, $kmByDate, $prescriptions, $inputs);
        while ($hardCeiling !== null && array_sum(array_map(static fn (IntensityPrescription $p): int => $p->hardMinutes, $prescriptions)) > $hardCeiling) {
            $hardMinutes = array_map(static fn (IntensityPrescription $p): int => $p->hardMinutes, $prescriptions);
            $largestHardMinutes = $hardMinutes === [] ? 0 : max($hardMinutes);
            $date = array_find_key($prescriptions, static fn (IntensityPrescription $candidate): bool => $candidate->hardMinutes === $largestHardMinutes);
            if ($date === null) {
                break;
            }
            $row = $rows[$date];
            $current = $prescriptions[$date];
            $over = array_sum(array_map(static fn (IntensityPrescription $p): int => $p->hardMinutes, $prescriptions)) - $hardCeiling;
            $family = IntensityPrescriptionResolver::familyKey(
                $row['session_type'],
                $inputs->raceDistanceM,
                $inputs->raceGoalTimeSec,
            );
            $recent = $inputs->recentPrescriptions[$family] ?? null;
            $prescriptions[$date] = $this->prescriptionResolver->resolve(
                $row['session_type'],
                $row['phase'],
                $inputs->raceDistanceM,
                $inputs->raceGoalTimeSec,
                $inputs->paces,
                $recent['verdict'] ?? null,
                $recent['hard_minutes'] ?? null,
                max(0, $current->hardMinutes - $over),
            );
            if ($prescriptions[$date]->hardMinutes === $current->hardMinutes) {
                break;
            }

            $hardCeiling = $this->hardCeiling($rows, $kmByDate, $prescriptions, $inputs);
        }

        foreach ($rows as $date => &$row) {
            $prescription = $prescriptions[$date];
            if (! $prescription->isEasy()) {
                $segments = SegmentGenerator::forPrescription($row['session_type'], $row['phase'], $kmByDate[$date], $inputs->paces, $prescription);
                $hasHard = array_any($segments, static fn (SessionSegment $segment): bool => in_array($segment->key, [SegmentKey::Main, SegmentKey::Interval], true) && $segment->paceLabel !== PaceBand::Easy);
                if (! $hasHard) {
                    $prescription = new IntensityPrescription(0, null, null, 'easy because the outing cannot safely fit the minimum quality structure', $prescription->raceContext);
                }
            }
            $row = [...$row, ...$prescription->toArray()];
        }
        unset($row);

        return self::capQualityAroundWeeklyHardDays($rows, $inputs->fixedSessions, $weekStart);
    }

    /**
     * @param array<string, array{phase: PlanPhase, session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}> $rows
     * @param array<string, array{session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null}> $fixedSessions
     * @param Carbon $weekStart
     * @return array<string, array{phase: PlanPhase, session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null, ...}>
     */
    private static function capQualityAroundWeeklyHardDays(array $rows, array $fixedSessions, Carbon $weekStart): array
    {
        $weekStartDate = $weekStart->toDateString();
        $weekEndDate = $weekStart->copy()->addDays(6)->toDateString();
        $fixedSessions = array_filter(
            $fixedSessions,
            static fn (string $date): bool => $date >= $weekStartDate && $date <= $weekEndDate,
            ARRAY_FILTER_USE_KEY,
        );
        $fixedHardOffsets = [];
        $longOffsets = [];
        foreach ($fixedSessions as $date => $session) {
            $offset = Carbon::parse($date)->dayOfWeekIso - 1;
            if (self::isHardDay($session)) {
                $fixedHardOffsets[] = $offset;
            }
            if ($session['session_type'] === SessionType::Long) {
                $longOffsets[] = $offset;
            }
        }

        foreach ($rows as $date => $row) {
            if ($row['session_type'] === SessionType::Long) {
                $longOffsets[] = Carbon::parse($date)->dayOfWeekIso - 1;
            }
        }

        $generatedHardLongDates = array_keys(array_filter($rows, static fn (array $row): bool =>
            $row['session_type'] === SessionType::Long
            && $row['prescribed_pace_band'] === PaceBand::Marathon
            && $row['prescribed_hard_minutes'] > 0));
        if (count($fixedHardOffsets) >= 2) {
            foreach ($generatedHardLongDates as $date) {
                $rows[$date] = [
                    ...$rows[$date],
                    'prescribed_hard_minutes' => 0,
                    'prescribed_pace_band' => null,
                    'prescribed_pace_sec_per_km' => null,
                    'prescription_reason' => 'easy because the weekly hard-day budget is already full',
                    'prescription_race_context' => null,
                ];
            }
            $generatedHardLongDates = [];
        }

        $qualityDates = array_keys(array_filter($rows, static fn (array $row): bool =>
            in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true)));
        if ($qualityDates === []) {
            return $rows;
        }

        $protectedOffsets = array_values(array_unique([...$fixedHardOffsets, ...$longOffsets]));
        $recoveryByDate = [];
        foreach ($qualityDates as $date) {
            $offset = Carbon::parse($date)->dayOfWeekIso - 1;
            $recoveryByDate[$date] = $protectedOffsets === []
                ? PHP_INT_MAX
                : min(array_map(static fn (int $protected): int => WeekPlanBuilder::longRunRecoveryDays($offset, $protected), $protectedOffsets));
        }
        usort($qualityDates, static fn (string $left, string $right): int =>
            ($recoveryByDate[$right] <=> $recoveryByDate[$left]) ?: strcmp($right, $left));

        $qualityLimit = max(0, 2 - count($fixedHardOffsets) - count($generatedHardLongDates));
        $keptHardOffsets = [];
        $keptQualityCount = 0;
        foreach ($qualityDates as $date) {
            $offset = Carbon::parse($date)->dayOfWeekIso - 1;
            $tooCloseToHardDay = array_any(
                [...$protectedOffsets, ...$keptHardOffsets],
                static fn (int $hardOffset): bool => WeekPlanBuilder::longRunRecoveryDays($offset, $hardOffset) < 2,
            );
            if ($keptQualityCount < $qualityLimit && ! $tooCloseToHardDay) {
                $keptQualityCount++;
                $keptHardOffsets[] = $offset;

                continue;
            }

            $rows[$date] = [
                ...$rows[$date],
                'session_type' => SessionType::Easy,
                'prescribed_hard_minutes' => 0,
                'prescribed_pace_band' => null,
                'prescribed_pace_sec_per_km' => null,
                'prescription_reason' => $tooCloseToHardDay
                    ? 'easy to preserve recovery between hard days'
                    : 'easy because the weekly hard-day budget is already full',
                'prescription_race_context' => null,
            ];
        }

        return $rows;
    }

    /**
     * Tempo and Interval sessions spend one hard day each; only a race-pace
     * Long with prescribed hard minutes spends one too.
     *
     * @param array{session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null} $row
     */
    private static function isHardDay(array $row): bool
    {
        return in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true)
            || ($row['session_type'] === SessionType::Long
                && $row['prescribed_pace_band'] === PaceBand::Marathon
                && $row['prescribed_hard_minutes'] > 0);
    }

    /** @param array<string, array{session_type: SessionType, phase: PlanPhase, ...}> $rows
     *  @param array<string, float> $kmByDate
     *  @param array<string, IntensityPrescription> $prescriptions
     */
    private function hardCeiling(array $rows, array $kmByDate, array $prescriptions, PlanInputs $inputs): ?int
    {
        if ($inputs->paces === null) {
            return null;
        }

        $totalMinutes = 0.0;
        foreach ($rows as $date => $row) {
            $segments = $row['session_type']->isQuality()
                ? SegmentGenerator::forPrescription($row['session_type'], $row['phase'], $kmByDate[$date], $inputs->paces, $prescriptions[$date])
                : SegmentGenerator::forCoreKm($row['session_type'], $row['phase'], $inputs->raceDistanceM, $kmByDate[$date], $inputs->paces, $inputs->raceGoalTimeSec);

            foreach ($segments as $segment) {
                if ($segment->minutes !== null) {
                    $totalMinutes += $segment->minutes;
                }
            }
        }

        return (int) floor($totalMinutes * 0.3);
    }

    /**
     * The volume floor this week gives way to, recorded so the Plan tab can
     * say so: only when the adapter's deload actually turned the week down,
     * which a Taper week never is.
     *
     * @param  array<string, array{phase: PlanPhase, session_type: SessionType, volume_multiplier: float, ...}>  $rows
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
     * `$flat` holds the arc at 1.0 outside its dips: a self-scaled arc always,
     * a race block while its increases are held.
     *
     * A season whose stored window outlasts its own arc — only reachable for a
     * self-scaled row written before the two were aligned — holds on its last
     * arc week rather than materializing nothing at all, until it rolls over.
     *
     * @param  list<array{week_start: Carbon, phase: PlanPhase, zone: string}>  $arc
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string, multiplier: float}>
     */
    private static function sliceFromCurrentWeek(array $arc, Carbon $arcStart, Carbon $currentWeekStart, bool $deload, bool $flat): array
    {
        if ($arc === []) {
            return [];
        }

        $offset = min(count($arc) - 1, max(0, (int) $arcStart->diffInWeeks($currentWeekStart)));

        $phases = array_map(static fn (array $week): PlanPhase => $week['phase'], $arc);
        if ($deload && $phases[$offset] !== PlanPhase::Taper) {
            $phases[$offset] = PlanPhase::Deload;
        }
        $multipliers = PhaseSchedule::volumeMultipliers($phases, $flat, array_column($arc, 'zone'));

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
