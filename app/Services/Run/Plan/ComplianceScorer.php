<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RecommendationRevision;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
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
    private const array STRONG_CONCERN_REASONS = ['severe_fatigue_or_soreness_reported', 'concerning_pain_reported', 'illness_reported'];

    public function __construct(
        private SessionMatcher $sessionMatcher,
        private TrainingBaseline $baseline,
        private RecommendationHistory $recommendationHistory,
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
        $recommendationsByDate = [];
        $runsByDate = $this->runsByDate($user, $first->date, $rangeEnd);
        $shownByActivity = $this->recommendationHistory->beforeRuns($user->id, array_merge(...array_values($runsByDate)));
        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            $anchor = collect($runsByDate[$date] ?? [])->sortByDesc('distance')->first();
            $recommendation = $anchor === null ? null : ($shownByActivity[$anchor->id] ?? null);
            if ($recommendation !== null) {
                $recommendationsByDate[$date] = $recommendation;
                $snapshot = clone $row;
                $snapshot->session_type = SessionType::from($recommendation->effective['session_type']);
                $snapshot->rest_clamped_at = null;
                $snapshot->clamped_km = null;
                $snapshot->eased_pace_sec_per_km = null;
                $plannedKmByDate[$date] = (float) $recommendation->effective['distance_km'];
                $effectiveByDate[$date] = EffectiveSession::of($snapshot, $plannedKmByDate[$date]);

                continue;
            }
            $baselineData = $longRunKmByDate[$date] ??= $this->baseline->forUser($user, $row->date);
            $longRunKm = (float) $baselineData['long_run_km'];
            $capKm = (float) $baselineData['long_run_cap_km'];
            $progressionCapKm = (float) $baselineData['long_run_progression_cap_km'];
            $selfScaled = $baselineData['self_scaled'];
            $byDate = $kmByBaseline["{$longRunKm}:{$capKm}:{$progressionCapKm}:{$selfScaled}"] ??= PlanRenderer::plannedKmByDate($contextRows, $longRunKm, $capKm, $selfScaled, $progressionCapKm);
            if (array_key_exists($date, $byDate)) {
                $effectiveByDate[$date] = EffectiveSession::of($row, $byDate[$date]);
                $plannedKmByDate[$date] = $effectiveByDate[$date]->coreKm;
            }
        }

        $excusedByDate = $rows->mapWithKeys(
            static fn (PlannedSession $session): array => [$session->date->toDateString() => $session->isExcused()],
        )->all();
        foreach ($recommendationsByDate as $date => $recommendation) {
            $excusedByDate[$date] = $recommendation->effective['skipped'] === true || $recommendation->effective['session_type'] === SessionType::Rest->value;
        }

        $typesByDate = array_map(static fn (RecommendationRevision $revision): SessionType => SessionType::from($revision->effective['session_type']), $recommendationsByDate);
        $verdicts = $this->sessionMatcher->scoreRange($user, $plannedKmByDate, $excusedByDate, $today, $typesByDate);
        $intents = $this->intentsFor($rows, $effectiveByDate, $verdicts, $recommendationsByDate, $runsByDate);

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
     * @param  array<string, RecommendationRevision>  $recommendationsByDate
     * @param  array<string, list<ActivityDetail>>  $runsByDate
     * @return array<string, array{verdict: IntentVerdict, evidence: array<string, int|float|string>}>
     */
    private function intentsFor(Collection $rows, array $effectiveByDate, array $verdicts, array $recommendationsByDate, array $runsByDate): array
    {
        $judged = $rows->filter(static fn (PlannedSession $row): bool => ($verdicts[$row->date->toDateString()]['status'] ?? null)?->isCredited() === true
            && in_array($effectiveByDate[$row->date->toDateString()]->sessionType, [SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval], true));
        if ($judged->isEmpty()) {
            return [];
        }

        $intents = [];
        foreach ($judged as $row) {
            $date = $row->date->toDateString();
            $recommendation = $recommendationsByDate[$date] ?? null;
            $intents[$date] = $recommendation === null
                ? ['verdict' => IntentVerdict::Unknown, 'evidence' => ['advice_history' => 'unknown']]
                : self::shownIntent($recommendation, $runsByDate[$date] ?? []);
        }

        return $intents;
    }

    /**
     * Grades the effective advice the athlete saw, and carries what the
     * original session was and what the runs actually did beside it, so an
     * eased session that got run hard, or an easy day with hard work added,
     * stays distinguishable from a plain miss.
     *
     * @param  list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function shownIntent(RecommendationRevision $recommendation, array $runs): array
    {
        $effectiveType = SessionType::from($recommendation->effective['session_type']);
        $effectiveSegments = self::segmentsOf($recommendation->effective['segments'] ?? []);
        $paces = $recommendation->effective['paces'] ?? null;
        $originalType = SessionType::tryFrom($recommendation->original['session_type'] ?? '') ?? $effectiveType;
        $originalSegments = self::segmentsOf($recommendation->original['segments'] ?? []);
        $originalHardMinutes = self::hardMinutes($originalSegments);
        $eased = $originalHardMinutes > 0.0
            && ($originalType !== $effectiveType || self::hardMinutes($effectiveSegments) < $originalHardMinutes);

        $reading = SessionIntentJudge::judge($effectiveType, $effectiveSegments, $paces, $runs);
        $evidence = $reading['evidence'] + ['recommendation_revision_id' => $recommendation->id, 'advice_history' => 'shown', 'effective_type' => $effectiveType->value];
        $verdict = $reading['verdict'];

        if (! $eased) {
            $evidence['concern'] = 'none';
            if ($originalHardMinutes > 0.0 && $verdict !== IntentVerdict::Unknown) {
                $evidence['quality_progression'] = 'eligible';
            }

            return ['verdict' => $verdict, 'evidence' => $evidence];
        }

        $evidence['eased_from'] = $originalType->value;
        $evidence['concern'] = self::concernOf($recommendation->effective['readiness_assessment'] ?? null);
        $original = SessionIntentJudge::judge($originalType, $originalSegments, $paces, $runs);
        if (in_array($original['verdict'], [IntentVerdict::Hit, IntentVerdict::TooHard], true)
            && in_array($original['evidence']['stimulus_family'] ?? null, ['tempo', 'interval', 'hard'], true)) {
            $evidence['original_completed'] = $original['evidence']['control'] ?? 'controlled';
            $evidence = array_diff_key($evidence, ['stimulus_family' => 0, 'stimulus_minutes' => 0, 'stimulus_source' => 0])
                + array_intersect_key($original['evidence'], ['stimulus_family' => 0, 'stimulus_minutes' => 0, 'stimulus_source' => 0]);
            $verdict = IntentVerdict::TooHard;
        }

        return ['verdict' => $verdict, 'evidence' => $evidence];
    }

    /**
     * A concern is strong only when the shown readiness ceiling was rest or
     * easy-only on a reported severe symptom; every other ease is mild.
     *
     * @param  array<string, mixed>|null  $assessment
     */
    private static function concernOf(?array $assessment): string
    {
        $stoppingCeiling = in_array($assessment['ceiling'] ?? null, [ReadinessCeiling::Rest->value, ReadinessCeiling::EasyOnly->value], true);
        $severe = array_intersect(self::STRONG_CONCERN_REASONS, $assessment['reasons'] ?? []) !== [];

        return $stoppingCeiling && $severe ? 'strong' : 'mild';
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<SessionSegment>
     */
    private static function segmentsOf(array $segments): array
    {
        return array_map(static fn (array $segment): SessionSegment => new SessionSegment(
            SegmentKey::from($segment['key']),
            $segment['minutes'],
            $segment['zone'],
            PaceBand::from($segment['pace_label']),
            $segment['pace_sec_per_km'],
            $segment['km'],
        ), $segments);
    }

    /** @param list<SessionSegment> $segments */
    private static function hardMinutes(array $segments): float
    {
        return array_sum(array_map(static fn (SessionSegment $segment): float => $segment->paceLabel === PaceBand::Easy ? 0.0 : ($segment->minutes ?? 0.0), $segments));
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
        if ($verdict === null) {
            return;
        }
        if (! $verdict['status']->isCredited()) {
            self::rewriteIntent($row, null);

            return;
        }
        if ($verdict['score'] !== null && $row->compliance_score !== null && $verdict['score'] <= $row->compliance_score) {
            self::rewriteIntent($row, $verdict['intent'] ?? null);

            return;
        }

        self::applyVerdict($row, $verdict);
    }

    /**
     * The earned score never moves down, but the intent and stimulus read
     * from the day's current runs replace what was stored when they differ,
     * so a revised, split, delayed or deleted recording cannot leave stale
     * evidence behind.
     *
     * @param  array{verdict: IntentVerdict, evidence: array<string, int|float|string>}|null  $intent
     */
    private static function rewriteIntent(PlannedSession $row, ?array $intent): void
    {
        $verdict = $intent['verdict'] ?? null;
        $evidence = $intent['evidence'] ?? null;
        if ($row->intent_verdict === $verdict && $row->intent_evidence == $evidence) {
            return;
        }

        $row->update(['intent_verdict' => $verdict, 'intent_evidence' => $evidence]);
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
