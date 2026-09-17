<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Turns {@see PlannedSession} rows into the verdict each one needs written
 * back. Shared by the daily `plan:score-compliance` pass, which judges every
 * past-due row at once, and by ingest, which judges the single day a run
 * just landed on.
 *
 * The trailing-week context is why this is one class rather than two copies:
 * scoring a lone week in isolation would let
 * {@see PlanRenderer::weekPhasesAndMultipliers()} read it as an isolated
 * week 1 and silently drop whatever Build ramp it is actually deep into, so
 * the prescribed km every verdict is measured against would be wrong.
 */
final readonly class ComplianceScorer
{
    public function __construct(
        private SessionMatcher $sessionMatcher,
        private TrainingBaseline $baseline,
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
        private ResolveActiveRaceAction $activeRace,
    ) {
    }

    /**
     * Each day is measured against the baseline **as it stood that day**, not
     * against today's. A verdict is a historical judgment, so it has to be
     * the same figure whether it is reached the morning after or three days
     * later when a delayed run finally syncs.
     *
     * `score` carries the session's intent as well as its distance;
     * `distance_score` is the distance ratio alone, and `intent` is null on a
     * day that was never credited.
     *
     * @param  Collection<int, PlannedSession>  $rows  the rows to judge
     * @return array<string, array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool, distance_score: int|null, prescribed_km: float|null, intent: array{verdict: IntentVerdict, evidence: array<string, int|float|string>}|null}>  Y-m-d => verdict
     */
    public function verdictsFor(User $user, Collection $rows, Carbon $today): array
    {
        $first = $rows->first();
        if ($first === null) {
            return [];
        }

        $rangeStart = $first->date->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(PlanRenderer::HISTORY_WEEKS);
        // A run's own local date can read a calendar day ahead of the server's,
        // for an athlete east of the app's timezone just after their midnight.
        $rangeEnd = $rows->pluck('date')->max();
        if ($rangeEnd === null || $rangeEnd->lessThan($today)) {
            $rangeEnd = $today;
        }

        $contextRows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->get();

        $longRunKmByDate = [];
        $kmByBaseline = [];
        $plannedKmByDate = [];
        $effectiveByDate = [];
        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            $baselineData = $longRunKmByDate[$date] ??= $this->baseline->forUser($user, $row->date);
            $longRunKm = (float) $baselineData['long_run_km'];
            $capKm = (float) $baselineData['long_run_cap_km'];
            $selfScaled = $baselineData['self_scaled'];
            $byDate = $kmByBaseline["{$longRunKm}:{$capKm}:{$selfScaled}"] ??= PlanRenderer::plannedKmByDate($contextRows, $longRunKm, $capKm, $selfScaled);
            if (array_key_exists($date, $byDate)) {
                $effectiveByDate[$date] = EffectiveSession::of($row, $byDate[$date]);
                $plannedKmByDate[$date] = $effectiveByDate[$date]->coreKm;
            }
        }

        $excusedByDate = $rows->mapWithKeys(
            static fn (PlannedSession $session): array => [$session->date->toDateString() => $session->isExcused()],
        )->all();

        $verdicts = $this->sessionMatcher->scoreRange($user, $plannedKmByDate, $excusedByDate, $today);
        $intents = $this->intentsFor($user, $rows, $effectiveByDate, $verdicts);

        $graded = [];
        foreach ($verdicts as $date => $verdict) {
            $intent = $intents[$date] ?? null;
            $graded[$date] = [
                ...SessionMatcher::withIntent($verdict, $intent['verdict'] ?? IntentVerdict::Unknown),
                'distance_score' => $verdict['score'],
                'prescribed_km' => $plannedKmByDate[$date] ?? null,
                'intent' => $intent,
            ];
        }

        return $graded;
    }

    /**
     * @param  Collection<int, PlannedSession>  $rows
     * @param  array<string, EffectiveSession>  $effectiveByDate
     * @param  array<string, array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}>  $verdicts
     * @return array<string, array{verdict: IntentVerdict, evidence: array<string, int|float|string>}>
     */
    private function intentsFor(User $user, Collection $rows, array $effectiveByDate, array $verdicts): array
    {
        $judged = $rows->filter(static fn (PlannedSession $row): bool => ($verdicts[$row->date->toDateString()]['status'] ?? null)?->isCredited() === true
            && in_array($effectiveByDate[$row->date->toDateString()]->sessionType, [SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval], true));
        if ($judged->isEmpty()) {
            return [];
        }

        $runsByDate = $this->runsByDate($user, $judged->first()->date, $judged->last()->date);
        $raceDistanceM = ($this->activeRace)($user->id)?->distance_m;

        $intents = [];
        foreach ($judged as $row) {
            $date = $row->date->toDateString();
            $effective = $effectiveByDate[$date];
            $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $row->date));
            $segments = $effective->isEased()
                ? SegmentGenerator::easyBlock($effective->coreKm, $paces)
                : SegmentGenerator::forCoreKm($effective->sessionType, $row->phase, $raceDistanceM === null ? null : (float) $raceDistanceM, $effective->coreKm, $paces);
            $intents[$date] = SessionIntentJudge::judge($effective->sessionType, $segments, $paces, $runsByDate[$date] ?? []);
        }

        return $intents;
    }

    /**
     * @return array<string, list<ActivityDetail>>
     */
    private function runsByDate(User $user, Carbon $from, Carbon $to): array
    {
        $details = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $user->id)
            ->whereBetween('activity_details.start_date_local', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('activity_details.start_date_local')
            ->get(['activity_details.*']);

        $byDate = [];
        foreach ($details as $detail) {
            if ($detail->start_date_local !== null) {
                $byDate[$detail->start_date_local->toDateString()][] = $detail;
            }
        }

        return $byDate;
    }

    /**
     * Writes one day's verdict the moment a run lands on it, but only ever
     * upward: a day the athlete has already earned is recorded, and anything
     * short of credited is left `Planned` for the daily pass to settle once
     * the day is actually over. Falling short is not decidable while the day
     * still has hours in it; clearing the bar cannot be undone by them.
     *
     * That asymmetry is also what corrects a late arrival. A run that syncs
     * after the daily pass has already written `missed` finds a row that is
     * no longer `Planned`, which nothing else would ever revisit, and lifts
     * it — while a second run on an already-credited day can raise `done` to
     * `overreached` but never the reverse.
     */
    public function creditIfEarned(User $user, Carbon $date, Carbon $today): void
    {
        $row = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $date->toDateString())
            ->first();
        if ($row === null) {
            return;
        }

        $verdict = $this->verdictsFor($user, Collection::wrap([$row]), $today)[$date->toDateString()] ?? null;
        if ($verdict === null || ! $verdict['status']->isCredited()) {
            return;
        }
        if ($verdict['score'] !== null && $row->compliance_score !== null && $verdict['score'] <= $row->compliance_score) {
            return;
        }

        self::applyVerdict($row, $verdict);
    }

    /**
     * Records the denominator beside the verdict. Prescribed km is otherwise
     * recomputed from the athlete's *current* baseline on every render, so a
     * past day would drift away from the figure it was actually judged
     * against as fitness moved.
     *
     * The intent verdict and its evidence are persisted here too, so a
     * narrator reading this row later gets the exact verdict the grade used
     * rather than recomputing one that could drift from it.
     *
     * @param  array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool, distance_score?: int|null, prescribed_km?: float|null, intent?: array{verdict: IntentVerdict, evidence: array<string, int|float|string>}|null}  $verdict
     */
    public static function applyVerdict(PlannedSession $row, array $verdict): void
    {
        $row->update([
            'status' => $verdict['status'],
            'compliance_score' => $verdict['score'],
            'distance_score' => $verdict['distance_score'] ?? null,
            'prescribed_km' => $verdict['prescribed_km'] ?? null,
            'ran_anyway' => $verdict['ran_anyway'],
            'intent_verdict' => $verdict['intent']['verdict'] ?? null,
            'intent_evidence' => $verdict['intent']['evidence'] ?? null,
        ]);
    }
}
