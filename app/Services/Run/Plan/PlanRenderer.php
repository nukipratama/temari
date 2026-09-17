<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Feedback\ResolveFlaggedSubjectsAction;
use App\Enums\FeedbackSubject;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
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
    public static function plannedKmByDate(Collection $sessions, float $longRunBaselineKm, float $longRunCapKm, bool $selfScaled): array
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
    public static function coreKmForSession(PlannedSession $session, float $longRunBaselineKm, float $longRunCapKm, bool $selfScaled): float
    {
        $weekStart = $session->date->copy()->startOfWeek(Carbon::MONDAY);
        $weekSessions = PlannedSession::query()
            ->where('user_id', $session->user_id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->get();

        return self::plannedKmByDate($weekSessions, $longRunBaselineKm, $longRunCapKm, $selfScaled)[$session->date->toDateString()]
            ?? SegmentGenerator::coreKmFor($session->session_type, false, $longRunBaselineKm, 1.0, $longRunCapKm, self::raceDistanceOf($session));
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
    ): float {
        return SegmentGenerator::segmentSumKm($segments)
            ?? round(SegmentGenerator::coreKmFor($sessionType, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM) * $volumeScale, 1);
    }

    /**
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null $clamp
     * @param  array<string, float>  $volumeScaleByDate  date => scale, from {@see VolumeRedistributor::redistribute()}
     * @param  bool  $isPrimaryEasy  whether this is the week's first (bigger) Easy day — see {@see SegmentGenerator::coreKmFor()}
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @param  array{km: float, runs: list<array{id: int, km: float, seconds: int|null, moving_time: int|null}>}|null  $activity  every run logged that day, for the planned-vs-actual bar and the links out
     * @param  ?int  $raceGoalTimeSec  the active race's `goal_time_sec` — what race day is prescribed at
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
    ): array {
        $isToday = $s->date->isSameDay($today);
        $volumeScale = $volumeScaleByDate[$s->date->toDateString()] ?? 1.0;

        // The row's own distance on race day, the active race's everywhere
        // else — where it only ever picks a pace band.
        $raceDistanceM = self::raceDistanceOf($s) ?? $raceDistanceM;
        // The plain, unredistributed ask — what Home's widget shows and what
        // the day's own narration is sized from (see PlanDayTool). Exposed so
        // the Plan page can say why `distance_km` moved, rather than the two
        // screens just disagreeing with no explanation.
        $askedKm = SegmentGenerator::coreKmFor($s->session_type, $isPrimaryEasy, $longRunKm, $multiplier, $longRunCapKm, $raceDistanceM);
        $effective = EffectiveSession::of($s, $askedKm);
        $sessionType = $effective->sessionType;
        $originalPaceSecPerKm = null;

        if ($effective->isEased()) {
            $segments = $sessionType === SessionType::Rest ? [] : SegmentGenerator::easyBlock($effective->coreKm, $paces);
            $askedKm = $distanceKm = $effective->coreKm;
        } else {
            // A pace ease keeps type and distance, so generation runs exactly
            // as it would have — the only change is 'easy'/'marathon' swapped
            // for the recorded slow end, which is why type/distance never move.
            $pacesForSegments = $paces;
            if ($effective->easedPaceSecPerKm !== null && $paces !== null) {
                $originalPaceSecPerKm = self::corePaceOf(SegmentGenerator::generate(
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
                ));
                $pacesForSegments = [
                    'easy' => $effective->easedPaceSecPerKm,
                    'marathon' => $effective->easedPaceSecPerKm,
                    'threshold' => $paces['threshold'],
                    'interval' => $paces['interval'],
                ];
            }

            $segments = SegmentGenerator::generate(
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
            );
        }

        return [
            'id' => $s->id,
            'date' => $s->date->toDateString(),
            'phase' => $s->phase->value,
            'session_type' => $sessionType->value,
            'segments' => array_map(static fn (SessionSegment $segment): array => $segment->toArray(), $segments),
            'distance_km' => $distanceKm,
            'asked_km' => $askedKm,
            'pinned' => $s->pinned,
            'skipped' => $s->skipped,
            'status' => $status->value,
            'compliance_score' => $s->compliance_score,
            'prescribed_km' => $s->prescribed_km,
            'ran_anyway' => $s->ran_anyway,
            'clamp' => $isToday && $clamp !== null && ! $effective->isEased() && ! $status->isCredited() ? self::clampPayload($clamp, $clampVoice) : null,
            'eased_from' => $effective->isEased() ? self::easedFromPayload($effective, $status, $isToday ? $clampVoice : null) : null,
            'pace_eased_from' => $effective->isPaceEased() ? [
                'pace_sec_per_km' => $originalPaceSecPerKm,
                'voice' => $status->isCredited() ? null : ReadinessClamp::paceEaseNote(),
            ] : null,
            'credit_note' => self::creditNote($sessionType, $status, $askedKm, $activity),
            'ran_pace_sec_per_km' => $status->isCredited() && $sessionType !== SessionType::Rest
                ? SessionMatcher::ranPaceSecPerKmFromRuns($s->session_type, $activity['runs'] ?? [])
                : null,
            'actual_km' => $activity['km'] ?? null,
            'activities' => array_map(
                static fn (array $run): array => ['id' => $run['id'], 'km' => $run['km'], 'seconds' => $run['seconds']],
                $activity['runs'] ?? [],
            ),
            'flagged' => app(ResolveFlaggedSubjectsAction::class)(FeedbackSubject::PlanDay, $s->id),
        ];
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
     */
    private static function easedFromPayload(EffectiveSession $effective, PlannedSessionStatus $status, ?string $clampVoice): array
    {
        $original = $effective->easedFromType ?? $effective->sessionType;
        $distanceHeld = $effective->distanceHeld();

        return [
            'session_type' => $original->value,
            'distance_km' => $distanceHeld ? null : $effective->easedFromKm,
            'voice' => $status->isCredited() ? null : $clampVoice ?? ReadinessClamp::noteFor($original, $effective->impliedCeiling()),
        ];
    }

    /**
     * A clamp that was never recorded, as a step-down *beside* the day's own
     * prescription, never in place of it. A recorded one is the day's session
     * itself (see {@see EffectiveSession} and
     * `docs/decisions/the-eased-session-leads.md`); this unrecorded one stays
     * advisory, so the eased version travels as its own object rather than
     * overwriting the stored fields. Carries a single
     * pace rather than the full segment list: the step-down is one line, and
     * only the core set's pace is ever shown on it.
     *
     * `note` is a permanent floor rather than a placeholder: the templated
     * string always renders, and the narrated line replaces it in place once
     * it lands. A step-down is therefore never unexplained, there is no pending
     * skeleton on a block that must always say something, and a paused or
     * cost-capped day still reads correctly.
     *
     * A credited day carries no clamp at all: the day is over, and the block
     * that once offered a second menu is replaced by what the day actually
     * came to. See `docs/decisions/a-credited-day-shows-its-result.md`.
     *
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string} $clamp
     * @return array{session_type: string, distance_km: float, pace_sec_per_km: int|null, note: string, label: string}
     */
    private static function clampPayload(array $clamp, ?string $voice): array
    {
        return [
            'session_type' => $clamp['session_type']->value,
            'distance_km' => $clamp['core_km'],
            'pace_sec_per_km' => self::corePaceOf($clamp['segments']),
            'note' => $voice ?? $clamp['note'],
            'label' => 'eased today',
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
     * @param  array{km: float, runs: list<array{id: int, km: float, seconds: int|null, moving_time: int|null}>}|null  $activity
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
}
