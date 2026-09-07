<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

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
 * {@see \App\Http\Controllers\PlanController} (the full multi-week arc) and
 * {@see CurrentWeekPlanBuilder} (Home's single-week widget). Pulled out so
 * the two pages can never numerically drift on the same week: the
 * phase→volume-multiplier math is relative to how far into a Peak/Taper/
 * Deload block a week sits, which only a shared computation over the same
 * trailing history can get right.
 */
final class PlanRenderer
{
    /**
     * $sessionsByWeek is keyed by week_start (Y-m-d), any order, each value
     * itself a Collection<int, PlannedSession> — the value type is left as
     * `mixed` rather than nested-Collection-typed, since groupBy()'s
     * nested-collection result doesn't satisfy Eloquent Collection's
     * Model-bound generics and Support Collection's own generics aren't
     * covariant either way.
     *
     * @param  Collection<string, mixed>  $sessionsByWeek
     * @return array{0: Collection<string, PlanPhase>, 1: array<string, float>}
     */
    public static function weekPhasesAndMultipliers(Collection $sessionsByWeek): array
    {
        $phaseByWeek = $sessionsByWeek->map(function ($weekSessions): PlanPhase {
            $first = $weekSessions->first();
            if ($first === null) {
                // groupBy never produces an empty group; this only guards the type.
                throw new LogicException('A grouped week unexpectedly had no sessions.');
            }

            return $first->phase;
        })->sortKeys();

        $multiplierByWeek = array_combine(
            $phaseByWeek->keys()->all(),
            PhaseSchedule::volumeMultipliers(array_values($phaseByWeek->values()->all())),
        );

        return [$phaseByWeek, $multiplierByWeek];
    }

    /**
     * The week's first Easy day gets the bigger (Medium) core-km fraction —
     * see {@see SegmentGenerator::coreKmFor()}'s `$isPrimaryEasy`. Shared by
     * every caller that needs a week's per-day km (`PlanController`,
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
     * dates the caller actually wants km for.
     *
     * A `Race` day is sized from its own stored `race_distance_m` rather than
     * the training baseline, so this keeps working once the goal behind it has
     * been retired — which it always has by the time `plan:score-compliance`
     * grades race day.
     *
     * @param  Collection<int, PlannedSession>  $sessions
     * @return array<string, float>  Y-m-d => core km
     */
    public static function plannedKmByDate(Collection $sessions, float $longRunBaselineKm): array
    {
        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );
        [, $multiplierByWeek] = self::weekPhasesAndMultipliers($sessionsByWeek);
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
                self::raceDistanceOf($s),
            );
        }

        return $plannedKmByDate;
    }

    /**
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null $clamp
     * @param  array<string, float>  $volumeScaleByDate  date => scale, from {@see VolumeRedistributor::redistribute()}
     * @param  bool  $isPrimaryEasy  whether this is the week's first (bigger) Easy day — see {@see SegmentGenerator::coreKmFor()}
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @param  array{km: float, runs: list<array{id: int, km: float, seconds: int|null}>}|null  $activity  every run logged that day, for the planned-vs-actual bar and the links out
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
        ?array $paces,
        PlannedSessionStatus $status,
        ?array $activity = null,
        ?string $clampVoice = null,
    ): array {
        $isToday = $s->date->isSameDay($today);
        $volumeScale = $volumeScaleByDate[$s->date->toDateString()] ?? 1.0;

        $sessionType = $s->session_type;
        // The row's own distance on race day, the active race's everywhere
        // else — where it only ever picks a pace band.
        $raceDistanceM = self::raceDistanceOf($s) ?? $raceDistanceM;
        $segments = SegmentGenerator::generate(
            $sessionType,
            $s->phase,
            $raceDistanceM,
            $isPrimaryEasy,
            $longRunKm,
            $multiplier,
            $paces,
            $volumeScale,
        );
        // The whole outing, and the figure the segments beneath it add up to.
        // An Interval day is the one that cannot land on its own budget — a
        // whole number of fixed-duration reps rarely does — so it reports what
        // its reps actually come to. Without a VDOT estimate nothing has a
        // distance yet, and the budget stands in.
        $distanceKm = SegmentGenerator::prescribedKm($segments)
            ?? round(SegmentGenerator::coreKmFor($sessionType, $isPrimaryEasy, $longRunKm, $multiplier, $raceDistanceM) * $volumeScale, 1);

        return [
            'id' => $s->id,
            'date' => $s->date->toDateString(),
            'phase' => $s->phase->value,
            'session_type' => $sessionType->value,
            'segments' => array_map(static fn (SessionSegment $segment): array => $segment->toArray(), $segments),
            'distance_km' => $distanceKm,
            'pinned' => $s->pinned,
            'skipped' => $s->skipped,
            'status' => $status->value,
            'compliance_score' => $s->compliance_score,
            'ran_anyway' => $s->ran_anyway,
            'clamp' => $isToday && $clamp !== null ? self::clampPayload($clamp, $clampVoice) : null,
            'actual_km' => $activity['km'] ?? null,
            'activities' => $activity['runs'] ?? [],
        ];
    }

    /** The distance a `Race` row stores for itself, and null on every other day. */
    private static function raceDistanceOf(PlannedSession $s): ?float
    {
        return $s->race_distance_m === null ? null : (float) $s->race_distance_m;
    }

    /**
     * The clamp as a step-down *beside* the day's own prescription, never in
     * place of it. The stored session stays the figure the card leads with,
     * the narrator describes and {@see SessionMatcher} grades, so the eased
     * version travels as its own object rather than overwriting those fields
     * — see `docs/decisions/readiness-clamp-is-advisory.md`. Carries a single
     * pace rather than the full segment list: the step-down is one line, and
     * only the core set's pace is ever shown on it.
     *
     * `note` is a permanent floor rather than a placeholder: the templated
     * string always renders, and the narrated line replaces it in place once
     * it lands. A step-down is therefore never unexplained, there is no pending
     * skeleton on a block that must always say something, and a paused or
     * cost-capped day still reads correctly.
     *
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string} $clamp
     * @return array{session_type: string, distance_km: float, pace_sec_per_km: int|null, note: string}
     */
    private static function clampPayload(array $clamp, ?string $voice): array
    {
        $core = null;
        foreach ($clamp['segments'] as $segment) {
            if (in_array($segment->key, [SegmentKey::Main, SegmentKey::Interval], true)) {
                $core = $segment;

                break;
            }
        }

        return [
            'session_type' => $clamp['session_type']->value,
            'distance_km' => $clamp['core_km'],
            'pace_sec_per_km' => $core?->paceSecPerKm,
            'note' => $voice ?? $clamp['note'],
        ];
    }
}
