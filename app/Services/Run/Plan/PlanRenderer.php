<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Feedback\ResolveFlaggedSubjectsAction;
use App\Enums\FallOffTilt;
use App\Enums\FeedbackSubject;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Services\Run\Metrics\DurationFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The two render-time computations shared by every "current week" surface —
 * {@see PlanPageAssembler} (the full multi-week arc) and
 * {@see CurrentWeekPlanBuilder} (Home's single-week widget). Pulled out so
 * the two pages can never numerically drift on the same week: the
 * phase→volume-multiplier math is relative to how far into a Peak/Taper/
 * Deload block a week sits, which only a shared computation over the same
 * trailing history can get right.
 *
 * That multiplier is now READ off the row rather than recomputed: it counts
 * from the season's arc start, which a window reaching back three weeks
 * cannot see. The recompute stays as the fallback for rows written before
 * generation stamped it. See `docs/decisions/the-arc-is-anchored-once.md`.
 */
final class PlanRenderer
{
    /**
     * Trailing weeks every caller of {@see self::weekPhasesAndMultipliers()}
     * must load around the weeks it actually wants. The recompute fallback
     * reads a week's Build ramp off its neighbours, so a window shorter than
     * this reads a week deep in a ramp as an isolated week 1. Declared once
     * here rather than per caller: nothing enforced that two copies stayed
     * equal.
     */
    public const int HISTORY_WEEKS = 3;

    /**
     * $sessionsByWeek is keyed by week_start (Y-m-d), any order, each value
     * itself a Collection<int, PlannedSession> — the value type is left as
     * `mixed` rather than nested-Collection-typed, since groupBy()'s
     * nested-collection result doesn't satisfy Eloquent Collection's
     * Model-bound generics and Support Collection's own generics aren't
     * covariant either way.
     *
     * `$selfScaled` only matters for the recompute fallback below: a stamped
     * week already carries the multiplier it was actually generated with, so
     * a self-scaled arc's flat ramp only needs re-deriving here for a week old
     * enough to predate {@see PlannedSession::$volume_multiplier} being
     * stamped at all — otherwise this would re-apply the race-arc ramp
     * {@see TrainingBaseline}'s `self_scaled` flag exists to hold at 1.0.
     *
     * @param  Collection<string, mixed>  $sessionsByWeek
     * @return array{0: Collection<string, PlanPhase>, 1: array<string, float>}
     */
    public static function weekPhasesAndMultipliers(Collection $sessionsByWeek, bool $selfScaled): array
    {
        $rowByWeek = $sessionsByWeek->map(fn ($weekSessions): PlannedSession => self::weekRow($weekSessions))->sortKeys();

        $phaseByWeek = $rowByWeek->map(fn (PlannedSession $row): PlanPhase => $row->phase);

        $stamped = [];
        foreach ($rowByWeek as $weekKey => $row) {
            if ($row->volume_multiplier !== null) {
                $stamped[$weekKey] = $row->volume_multiplier;
            }
        }

        // A row written before generation started stamping its own multiplier
        // has none to read, and a window mixing stamped and unstamped weeks
        // cannot be read either way — so the whole window falls back to the
        // recompute, which is what every week used to get.
        $multiplierByWeek = count($stamped) === $phaseByWeek->count()
            ? $stamped
            : array_combine(
                $phaseByWeek->keys()->all(),
                PhaseSchedule::volumeMultipliers(array_values($phaseByWeek->values()->all()), $selfScaled),
            );

        return [$phaseByWeek, $multiplierByWeek];
    }

    /**
     * The row a week reads its phase AND multiplier from — one row, so the
     * two can never disagree with each other. Regeneration never rewrites a
     * pinned row, nor one already carrying a verdict, nor a date already
     * behind it, so a week can hold a stale row from an older generation
     * next to fresh ones. Every regeneration writes forward to the week's
     * end, so the latest-dated row it could still own is the one carrying
     * its most recent decision; only a week pinned to the last day reads a
     * pinned row.
     *
     * @param  Collection<int, PlannedSession>  $weekSessions
     */
    private static function weekRow(Collection $weekSessions): PlannedSession
    {
        $byDate = $weekSessions->sortBy(fn (PlannedSession $s): string => $s->date->toDateString());
        $row = $byDate->last(fn (PlannedSession $s): bool => ! $s->pinned) ?? $byDate->last();
        if ($row === null) {
            // groupBy never produces an empty group; this only guards the type.
            throw new LogicException('A grouped week unexpectedly had no sessions.');
        }

        return $row;
    }

    /**
     * The week's first Easy day gets the bigger (Medium) core-km fraction —
     * see {@see SegmentGenerator::coreKmFor()}'s `$isPrimaryEasy`. Shared by
     * every caller that needs a week's per-day km (`PlanPageAssembler`,
     * `CurrentWeekPlanBuilder`, `plan:score-compliance`) so none of them
     * silently drift on which day is "primary".
     *
     * @param  Collection<int, PlannedSession>  $weekSessions
     */
    public static function primaryEasyDate(Collection $weekSessions): ?string
    {
        return $weekSessions
            ->sortBy(fn (PlannedSession $s): string => $s->date->toDateString())
            ->first(fn (PlannedSession $s): bool => $s->session_type === SessionType::Easy)
            ?->date?->toDateString();
    }

    /**
     * Every session's core km, keyed by date — the shared computation behind
     * `distance_km`/`SessionMatcher`'s planned-km input. `$sessions` should
     * include enough trailing history for {@see self::weekPhasesAndMultipliers()}'s
     * ramp to be correct for the *earliest* week being scored, not just the
     * dates the caller actually wants km for — which matters only while the
     * range still holds a row written before the multiplier was stamped.
     *
     * A `Race` day is sized from its own stored `race_distance_m` rather than
     * the training baseline, so this keeps working once the goal behind it has
     * been retired — which it always has by the time `plan:score-compliance`
     * grades race day.
     *
     * @param  Collection<int, PlannedSession>  $sessions
     * @return array<string, float>  Y-m-d => core km
     */
    public static function plannedKmByDate(Collection $sessions, float $longRunBaselineKm, float $longRunCapKm, bool $selfScaled, float $longRunProgressionCapKm = INF): array
    {
        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );
        [, $multiplierByWeek] = self::weekPhasesAndMultipliers($sessionsByWeek, $selfScaled);
        $primaryEasyDateByWeek = $sessionsByWeek->map(
            fn (Collection $weekSessions): ?string => self::primaryEasyDate($weekSessions),
        );

        $plannedKmByDate = [];
        foreach ($sessions as $s) {
            $weekKey = $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $plannedKmByDate[$s->date->toDateString()] = SegmentGenerator::coreKmFor(
                $s->session_type,
                $s->date->toDateString() === $primaryEasyDateByWeek->get($weekKey),
                $longRunBaselineKm,
                $multiplierByWeek[$weekKey] ?? 1.0,
                $longRunCapKm,
                self::raceDistanceOf($s),
                $longRunProgressionCapKm,
                $s->fall_off_tilt,
                $s->prescription_race_context,
            );
        }

        return $plannedKmByDate;
    }

    /**
     * One session's core km, for a caller holding a single row rather than an
     * already-loaded week — looks up its own week's siblings so
     * {@see self::plannedKmByDate()}'s `isPrimaryEasy`/multiplier still apply.
     * Still the plain, unredistributed figure: no {@see VolumeRedistributor}
     * scale reaches this.
     */
    public static function coreKmForSession(PlannedSession $session, float $longRunBaselineKm, float $longRunCapKm, bool $selfScaled, float $longRunProgressionCapKm = INF): float
    {
        $weekStart = $session->date->copy()->startOfWeek(Carbon::MONDAY);
        $weekSessions = PlannedSession::query()
            ->where('user_id', $session->user_id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->get();

        return self::plannedKmByDate($weekSessions, $longRunBaselineKm, $longRunCapKm, $selfScaled, $longRunProgressionCapKm)[$session->date->toDateString()]
            ?? SegmentGenerator::coreKmFor($session->session_type, false, $longRunBaselineKm, 1.0, $longRunCapKm, self::raceDistanceOf($session), $longRunProgressionCapKm, $session->fall_off_tilt, $session->prescription_race_context);
    }

    /**
     * The whole outing, and the figure the segments beneath it add up to.
     * An Interval day is the one that cannot land on its own budget — a
     * whole number of fixed-duration reps rarely does — so it reports what
     * its reps actually come to. Without a VDOT estimate nothing has a
     * distance yet, and the budget stands in, scaled by a redistributed
     * week's own scale.
     *
     * @param  list<SessionSegment>  $segments  from {@see SegmentGenerator::generate()} for this same day
     */
    public static function sessionDistanceKm(
        array $segments,
        SessionType $sessionType,
        bool $isPrimaryEasy,
        float $longRunKm,
        float $multiplier,
        float $longRunCapKm,
        ?float $raceDistanceM,
        float $volumeScale = 1.0,
        float $longRunProgressionCapKm = INF,
        ?FallOffTilt $fallOffTilt = null,
    ): float {
        $segmentKm = SegmentGenerator::segmentSumKm($segments);
        if ($segmentKm !== null) {
            return $segmentKm;
        }

        $distanceKm = SegmentGenerator::coreKmFor($sessionType, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm, $fallOffTilt) * $volumeScale;
        if ($sessionType === SessionType::Long) {
            $distanceKm = min($distanceKm, $longRunCapKm, $longRunProgressionCapKm);
        }

        return round($distanceKm, 1);
    }

    /**
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string, quality_dose?: array{hard_minutes: int, original_hard_minutes: int, pace_band: string, pace_sec_per_km: int|null}|null}|null $clamp  today's advisory ease, which leads the day unless the row is pinned or a race
     * @param  array<string, float>  $volumeScaleByDate  date => scale, from {@see VolumeRedistributor::redistribute()}
     * @param  bool  $isPrimaryEasy  whether this is the week's first (bigger) Easy day — see {@see SegmentGenerator::coreKmFor()}
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @param  array{km: float, runs: list<array{id: int, km: float, seconds: int|null, started_at: string}>}|null  $activity  every run logged that day, for the planned-vs-actual bar and the links out
     * @param  ?int  $raceGoalTimeSec  the active race's `goal_time_sec` — what race day is prescribed at
     * @param  ?bool  $ranAnyway  live verdict override for an unscored day; null keeps the row's own stored value
     * @param  array<string, mixed>|null  $readinessAssessment  today's deterministic inputs and reasons
     * @return array<string, mixed>
     */
    public static function dayPayload(
        PlannedSession $s,
        Carbon $today,
        ?array $clamp,
        array $volumeScaleByDate,
        ?float $raceDistanceM,
        bool $isPrimaryEasy,
        float $longRunKm,
        float $multiplier,
        float $longRunCapKm,
        ?array $paces,
        PlannedSessionStatus $status,
        ?array $activity = null,
        ?string $clampVoice = null,
        ?int $raceGoalTimeSec = null,
        float $longRunProgressionCapKm = INF,
        ?bool $ranAnyway = null,
        ?array $readinessAssessment = null,
        ?int $easyHrCapBpm = null,
    ): array {
        $isToday = $s->date->isSameDay($today);
        $currentReadinessAssessment = $isToday && ! $status->isCredited()
            ? ($readinessAssessment ?? $s->readiness_assessment)
            : $s->readiness_assessment;
        $readinessReasons = $currentReadinessAssessment['reasons'] ?? [];
        $volumeScale = $volumeScaleByDate[$s->date->toDateString()] ?? 1.0;

        // The row's own distance on race day, the active race's everywhere
        // else — where it only ever picks a pace band.
        $raceDistanceM = self::raceDistanceOf($s) ?? $raceDistanceM;
        // The plain, unredistributed ask — what Home's widget shows and what
        // the day's own narration is sized from (see PlanDayTool). Exposed so
        // the Plan page can say why `distance_km` moved, rather than the two
        // screens just disagreeing with no explanation.
        $askedKm = SegmentGenerator::coreKmFor($s->session_type, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm, $s->fall_off_tilt, $s->prescription_race_context);
        $effective = EffectiveSession::of($s, $askedKm);
        $recordedReasons = $s->readiness_assessment['reasons'] ?? $readinessReasons;
        $headlinesEase = $effective->isEased();
        $advisoryClamp = $isToday && ! $status->isCredited() && ! $headlinesEase ? $clamp : null;
        $keepsPrescription = $s->pinned || $s->session_type === SessionType::Race;
        $headlinesAdvice = $advisoryClamp !== null && ! $keepsPrescription;
        $sessionType = match (true) {
            $headlinesEase => $effective->sessionType,
            $headlinesAdvice => $advisoryClamp['session_type'],
            default => $s->session_type,
        };
        $storedPrescription = IntensityPrescription::fromSession($s);
        if (! $headlinesEase && ! $headlinesAdvice && $storedPrescription?->isEasy() === true && in_array($sessionType, [SessionType::Tempo, SessionType::Interval], true)) {
            $sessionType = SessionType::Easy;
        }
        $originalPaceSecPerKm = null;
        $originalAskedKm = $askedKm;
        $fallOffTilt = self::shownFallOffTilt($s, $sessionType, $headlinesEase || $headlinesAdvice, $askedKm, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm);

        if ($headlinesEase) {
            $segments = self::stepDownFromEffective($effective, $paces, $recordedReasons, $s->phase)['segments'];
            $askedKm = $distanceKm = $effective->coreKm;
        } elseif ($headlinesAdvice) {
            $segments = $advisoryClamp['segments'];
            $askedKm = $distanceKm = $advisoryClamp['core_km'];
        } else {
            // A pace ease keeps type and distance, so generation runs exactly
            // as it would have — the only change is 'easy'/'marathon' swapped
            // for the recorded slow end, which is why type/distance never move.
            $pacesForSegments = $paces;
            if ($effective->easedPaceSecPerKm !== null && $paces !== null) {
                $originalPaceSecPerKm = self::corePaceOf(self::segmentsFor(
                    $s,
                    $sessionType,
                    $s->phase,
                    $raceDistanceM,
                    $isPrimaryEasy,
                    $longRunKm,
                    $multiplier,
                    $longRunCapKm,
                    $paces,
                    $volumeScale,
                    $raceGoalTimeSec,
                    $longRunProgressionCapKm,
                ));
                $pacesForSegments = [
                    'easy' => $effective->easedPaceSecPerKm,
                    'marathon' => $effective->easedPaceSecPerKm,
                    'threshold' => $paces['threshold'],
                    'interval' => $paces['interval'],
                ];
            }

            $segments = self::segmentsFor(
                $s,
                $sessionType,
                $s->phase,
                $raceDistanceM,
                $isPrimaryEasy,
                $longRunKm,
                $multiplier,
                $longRunCapKm,
                $pacesForSegments,
                $volumeScale,
                $raceGoalTimeSec,
                $longRunProgressionCapKm,
            );
            $distanceKm = self::sessionDistanceKm(
                $segments,
                $sessionType,
                $isPrimaryEasy,
                $longRunKm,
                $multiplier,
                $longRunCapKm,
                $raceDistanceM,
                $volumeScale,
                $longRunProgressionCapKm,
                $s->fall_off_tilt,
            );
        }

        $longestRunKm = null;
        foreach ($activity['runs'] ?? [] as $run) {
            $longestRunKm = $longestRunKm === null ? $run['km'] : max($longestRunKm, $run['km']);
        }
        $creditedKm = $activity === null || $longestRunKm === null ? null : round(SessionMatcher::creditedKm($s->session_type, [
            'sum' => $activity['km'],
            'longest' => $longestRunKm,
        ], TimeTrial::of($s) !== null), 1);
        $ranPaceSecPerKm = $status->isCredited() && $sessionType !== SessionType::Rest
            ? SessionMatcher::ranPaceSecPerKmFromRuns($s->session_type, $activity['runs'] ?? [], TimeTrial::of($s) !== null)
            : null;

        $originalSegments = self::segmentsFor($s, $s->session_type, $s->phase, $raceDistanceM, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $paces, $volumeScale, $raceGoalTimeSec, $longRunProgressionCapKm);
        $shownClamp = $advisoryClamp;
        if ($headlinesEase && $isToday && ! $status->isCredited()) {
            $shownClamp = self::stepDownFromEffective($effective, $paces, $recordedReasons, $s->phase);
        }
        $recommendationToken = app(RecommendationHistory::class)->token($s->user_id, $s->date->toDateString(), [
            'session_type' => $s->session_type->value,
            'phase' => $s->phase->value,
            'hard_minutes' => $s->prescribed_hard_minutes,
            'distance_km' => SegmentGenerator::segmentSumKm($originalSegments) ?? $askedKm,
            'reason' => $s->prescription_reason,
            'segments' => array_map(static fn (SessionSegment $segment): array => $segment->toArray(), $originalSegments),
        ], [
            'session_type' => ($shownClamp['session_type'] ?? $sessionType)->value,
            'distance_km' => $shownClamp['core_km'] ?? $distanceKm,
            'segments' => array_map(static fn (SessionSegment $segment): array => $segment->toArray(), $shownClamp['segments'] ?? $segments),
            'paces' => $paces,
            'skipped' => $s->skipped,
            'reason' => $shownClamp['note'] ?? ($effective->isEased() ? ReadinessClamp::noteFor($s->session_type, $effective->impliedCeiling(), $readinessReasons) : $s->prescription_reason),
            'readiness_assessment' => $effective->isEased() ? $s->readiness_assessment : $currentReadinessAssessment,
        ]);
        $goalPace = self::goalPaceKindOf($s, $sessionType);

        return [
            'id' => $s->id,
            'recommendation_token' => $recommendationToken,
            'readiness_assessment' => $currentReadinessAssessment,
            'date' => $s->date->toDateString(),
            'phase' => $s->phase->value,
            'session_type' => $sessionType->value,
            'segments' => array_map(static fn (SessionSegment $segment): array => $segment->toArray(), $segments),
            'hr_cap_bpm' => self::heartRateCapOf($sessionType, $segments, $easyHrCapBpm),
            'distance_km' => $distanceKm,
            'asked_km' => $askedKm,
            'pinned' => $s->pinned,
            'skipped' => $s->skipped,
            'status' => $status->value,
            'compliance_score' => $s->compliance_score,
            'prescribed_km' => $s->prescribed_km,
            'ran_anyway' => $ranAnyway ?? $s->ran_anyway,
            'prescription_reason' => $s->prescription_reason,
            'fall_off_tilt' => $fallOffTilt?->value,
            'goal_pace' => $goalPace,
            'stepping_stone' => $goalPace !== null && GoalPaceWork::isSteppingStone($s->prescription_race_context),
            'time_trial' => self::timeTrialOf($s, $sessionType),
            'advice_note' => $advisoryClamp !== null && $keepsPrescription ? $clampVoice ?? $advisoryClamp['note'] : null,
            'eased_from' => match (true) {
                $headlinesEase => self::easedFromPayload($effective, $status, $isToday ? $clampVoice : null, $recordedReasons),
                $headlinesAdvice => [
                    'session_type' => $s->session_type->value,
                    'distance_km' => abs($originalAskedKm - $advisoryClamp['core_km']) < 0.05 ? null : $originalAskedKm,
                    'voice' => $clampVoice ?? $advisoryClamp['note'],
                ],
                default => null,
            },
            'pace_eased_from' => $effective->isPaceEased() ? [
                'pace_sec_per_km' => $originalPaceSecPerKm,
                'voice' => $status->isCredited() ? null : ReadinessClamp::paceEaseNote($readinessReasons),
            ] : null,
            'credit_note' => self::creditNote($sessionType, $status, $askedKm, $activity),
            'ran_hot' => self::ranHot($s, $status),
            'result_note' => self::resultNote($s, $status, $ranPaceSecPerKm),
            'ran_pace_sec_per_km' => $ranPaceSecPerKm,
            'actual_km' => $activity['km'] ?? null,
            'credited_km' => $creditedKm,
            'activities' => array_map(
                static fn (array $run): array => ['id' => $run['id'], 'km' => $run['km'], 'seconds' => $run['seconds'], 'started_at' => $run['started_at']],
                $activity['runs'] ?? [],
            ),
            'flagged' => app(ResolveFlaggedSubjectsAction::class)(FeedbackSubject::PlanDay, $s->id),
        ];
    }

    /**
     * The heart rate an easy or long run is held under, or null when the
     * athlete's zones are the config default or the session has no easy running.
     *
     * @param  list<SessionSegment>  $segments
     */
    private static function heartRateCapOf(SessionType $shownType, array $segments, ?int $easyHrCapBpm): ?int
    {
        $easyRunning = array_any($segments, static fn (SessionSegment $segment): bool => $segment->paceLabel === PaceBand::Easy);

        return in_array($shownType, [SessionType::Easy, SessionType::Long], true) && $easyRunning ? $easyHrCapBpm : null;
    }

    /** The race whose goal pace the session shown rehearses, or null on any other session. */
    public static function goalPaceKindOf(PlannedSession $s, SessionType $shownType): ?string
    {
        $kind = $s->prescription_race_context['kind'] ?? null;
        if ($shownType !== $s->session_type
            || ! in_array($shownType, [SessionType::Tempo, SessionType::Interval], true)
            || ! GoalPaceWork::isGoalPace($s->prescription_race_context)
            || IntensityPrescription::fromSession($s)?->isEasy() !== false
            || ! is_string($kind)) {
            return null;
        }

        return $kind;
    }

    /**
     * The goal-pace label the plan tools hand a narrator: `stepping_stone`
     * for an unsupported goal's stepping-stone pace, `goal_pace` otherwise.
     *
     * @return array{goal_pace?: string, stepping_stone?: string}
     */
    public static function goalPaceForNarration(PlannedSession $s, SessionType $shownType): array
    {
        $kind = self::goalPaceKindOf($s, $shownType);

        return match (true) {
            $kind === null => [],
            GoalPaceWork::isSteppingStone($s->prescription_race_context) => ['stepping_stone' => $kind],
            default => ['goal_pace' => $kind],
        };
    }

    /**
     * The time trial the session shown is, or null on any other session.
     *
     * @return array{distance_m: int, aim_time_sec: int}|null
     */
    public static function timeTrialOf(PlannedSession $s, SessionType $shownType): ?array
    {
        $trial = $shownType === $s->session_type ? TimeTrial::of($s) : null;

        return $trial === null ? null : ['distance_m' => $trial->distanceM, 'aim_time_sec' => $trial->aimTimeSec];
    }

    /**
     * @param  array{distance_m: int, aim_time_sec: int}  $trial
     * @return array{distance_km: float, aim_time: string}
     */
    public static function timeTrialForNarration(array $trial): array
    {
        return ['distance_km' => round($trial['distance_m'] / 1000, 1), 'aim_time' => DurationFormatter::hms($trial['aim_time_sec'])];
    }

    /**
     * The tilt that shaped the session shown, or null once an ease or a
     * kept-easy dose replaced it, or when the caps left a tilted long run no longer.
     */
    private static function shownFallOffTilt(
        PlannedSession $s,
        SessionType $shownType,
        bool $eased,
        float $askedKm,
        bool $isPrimaryEasy,
        float $longRunKm,
        float $multiplier,
        float $longRunCapKm,
        ?float $raceDistanceM,
        float $longRunProgressionCapKm,
    ): ?FallOffTilt {
        if ($s->fall_off_tilt === null || $eased || $shownType !== $s->session_type) {
            return null;
        }
        if ($shownType === SessionType::Long
            && $askedKm <= SegmentGenerator::coreKmFor($shownType, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm)) {
            return null;
        }

        return $s->fall_off_tilt;
    }

    /**
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     * @return list<SessionSegment>
     */
    private static function segmentsFor(
        PlannedSession $session,
        SessionType $sessionType,
        PlanPhase $phase,
        ?float $raceDistanceM,
        bool $isPrimaryEasy,
        float $longRunKm,
        float $multiplier,
        float $longRunCapKm,
        ?array $paces,
        float $volumeScale,
        ?int $raceGoalTimeSec,
        float $longRunProgressionCapKm,
    ): array {
        $prescription = IntensityPrescription::fromSession($session);
        if ($prescription?->isEasy() === true && $sessionType === SessionType::Easy && in_array($session->session_type, [SessionType::Tempo, SessionType::Interval], true)) {
            $km = SegmentGenerator::coreKmFor($session->session_type, false, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm, $session->fall_off_tilt) * $volumeScale;

            return SegmentGenerator::easyBlock(round($km, 1), $paces);
        }
        if ($prescription === null || ! $sessionType->isQuality()) {
            return SegmentGenerator::generate($sessionType, $phase, $raceDistanceM, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $paces, $volumeScale, $raceGoalTimeSec, $longRunProgressionCapKm, $session->fall_off_tilt);
        }

        $km = SegmentGenerator::coreKmFor($sessionType, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM, $longRunProgressionCapKm, $session->fall_off_tilt, $session->prescription_race_context) * $volumeScale;
        if ($sessionType === SessionType::Long) {
            $km = min($km, $longRunCapKm, $longRunProgressionCapKm);
        }

        return SegmentGenerator::forPrescription($sessionType, $phase, round($km, 1), $paces, $prescription);
    }

    /** The distance a `Race` row stores for itself, and null on every other day. */
    private static function raceDistanceOf(PlannedSession $s): ?float
    {
        return $s->race_distance_m === null ? null : (float) $s->race_distance_m;
    }

    /**
     * What an eased day was eased from, and before credit the line that is the
     * day's voice: the narrated clamp explanation, or the templated note for
     * the recorded outcome until one lands. A distance that did not move is
     * left out, so an intensity-only ease names the session alone.
     *
     * @return array{session_type: string, distance_km: float|null, voice: string|null}
     * @param list<string> $reasons
     */
    private static function easedFromPayload(EffectiveSession $effective, PlannedSessionStatus $status, ?string $clampVoice, array $reasons = []): array
    {
        $original = $effective->easedFromType ?? $effective->sessionType;
        $distanceHeld = $effective->distanceHeld();
        $dose = $effective->qualityDose;

        return [
            'session_type' => $original->value,
            'distance_km' => $distanceHeld ? null : $effective->easedFromKm,
            'voice' => $status->isCredited() ? null : $clampVoice ?? ($dose === null
                ? ReadinessClamp::noteFor($original, $effective->impliedCeiling(), $reasons)
                : ReadinessClamp::qualityDoseNote($original, $reasons, $dose['hard_minutes'], $dose['original_hard_minutes'], $dose['pace_sec_per_km'])),
        ];
    }

    /**
     * The eased session's type, segments, distance and reason, which lead the day once an ease is recorded.
     *
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @param list<string> $reasons
     * @return array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string, quality_dose: array{hard_minutes: int, original_hard_minutes: int, pace_band: string, pace_sec_per_km: int|null}|null}
     */
    private static function stepDownFromEffective(EffectiveSession $effective, ?array $paces, array $reasons = [], PlanPhase $phase = PlanPhase::Build): array
    {
        $original = $effective->easedFromType ?? $effective->sessionType;
        $segments = $effective->sessionType === SessionType::Rest ? [] : SegmentGenerator::easyBlock($effective->coreKm, $paces);
        $prescription = $effective->qualityPrescription();
        if ($prescription !== null) {
            $segments = SegmentGenerator::forPrescription(
                $effective->sessionType,
                $phase,
                $effective->coreKm,
                $paces,
                $prescription
            );
        }

        return [
            'session_type' => $effective->sessionType,
            'segments' => $segments,
            'core_km' => $effective->coreKm,
            'note' => $effective->qualityDose === null
                ? ReadinessClamp::noteFor($original, $effective->impliedCeiling(), $reasons) ?? ''
                : ReadinessClamp::qualityDoseNote($original, $reasons, $effective->qualityDose['hard_minutes'], $effective->qualityDose['original_hard_minutes'], $effective->qualityDose['pace_sec_per_km']),
            'quality_dose' => $effective->qualityDose,
        ];
    }

    /**
     * The pace of a session's core set — its Main block, or the shared pace
     * every Interval rep runs at. Shared by every payload that shows a single
     * pace figure for a whole session rather than its full segment breakdown.
     *
     * @param  list<SessionSegment>  $segments
     */
    private static function corePaceOf(array $segments): ?int
    {
        foreach ($segments as $segment) {
            if (in_array($segment->key, [SegmentKey::Main, SegmentKey::Interval], true)) {
                return $segment->paceSecPerKm;
            }
        }

        return null;
    }

    /**
     * Why a long day that covered its distance still reads `partial`: the
     * volume arrived in pieces. Only that case has something to explain —
     * every other verdict is already said by its own numbers.
     *
     * @param  array{km: float, runs: list<array{id: int, km: float, seconds: int|null, started_at: string}>}|null  $activity
     */
    private static function creditNote(SessionType $sessionType, PlannedSessionStatus $status, float $askedKm, ?array $activity): ?string
    {
        if ($sessionType !== SessionType::Long || $status !== PlannedSessionStatus::Partial || $activity === null || $askedKm <= 0.0) {
            return null;
        }

        $longestKm = max(array_map(static fn (array $run): float => $run['km'], $activity['runs']) ?: [0.0]);
        if ($activity['km'] < $askedKm * SessionMatcher::DONE_FRACTION || SessionMatcher::oneRunCarriedTheLongDay($askedKm, $longestKm)) {
            return null;
        }

        return 'the distance was there, but not in one run. a long day is time on feet in one go.';
    }

    /** An overreached day graded on intent rather than distance: it ran too hard, not too far. */
    private static function ranHot(PlannedSession $s, PlannedSessionStatus $status): bool
    {
        return $status === PlannedSessionStatus::Overreached && $s->intent_verdict === IntentVerdict::TooHard
            && ($s->compliance_score === null || $s->compliance_score < (int) round(SessionMatcher::OVERREACHED_FRACTION * 100));
    }

    /**
     * What a run came to against the advice shown, in the grading's own words:
     * an eased session run as written, a strong-concern day run hard, hard work
     * added to an easy day, or an effort the data can't read. Null on a day
     * still to run and on a plain hit or miss, which the status already says.
     */
    private static function resultNote(PlannedSession $s, PlannedSessionStatus $status, ?int $ranPaceSecPerKm): ?string
    {
        if (! $status->isCredited()) {
            return null;
        }

        $evidence = $s->intent_evidence ?? [];

        return match ($s->intent_verdict) {
            IntentVerdict::TooHard => self::clauses(
                IntentOutcome::outcome(IntentVerdict::TooHard, $evidence),
                IntentOutcome::detail(IntentVerdict::TooHard, $evidence, $ranPaceSecPerKm),
            ),
            IntentVerdict::Unknown => IntentOutcome::outcome(IntentVerdict::Unknown, $evidence).', so the day counts its distance only.',
            default => null,
        };
    }

    private static function clauses(string $outcome, ?string $detail): string
    {
        return $detail === null ? "{$outcome}." : "{$outcome}; {$detail}.";
    }
}
