<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

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
            );
        }

        return $plannedKmByDate;
    }

    /**
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null $clamp
     * @param  array<string, float>  $volumeScaleByDate  date => scale, from {@see VolumeRedistributor::redistribute()}
     * @param  bool  $isPrimaryEasy  whether this is the week's first (bigger) Easy day — see {@see SegmentGenerator::coreKmFor()}
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return array<string, mixed>
     */
    public static function dayPayload(
        PlannedSession $s,
        Carbon $today,
        ?array $clamp,
        array $volumeScaleByDate,
        bool $isMarathonDistance,
        bool $isPrimaryEasy,
        float $longRunKm,
        float $multiplier,
        ?array $paces,
        PlannedSessionStatus $status,
    ): array {
        $isToday = $s->date->isSameDay($today);
        $volumeScale = $volumeScaleByDate[$s->date->toDateString()] ?? 1.0;

        if ($isToday && $clamp !== null) {
            $sessionType = $clamp['session_type'];
            $segments = $clamp['segments'];
            // The clamp already scaled itself down for readiness; volume
            // redistribution never also applies on top of a clamped today.
            $distanceKm = $clamp['core_km'];
        } else {
            $sessionType = $s->session_type;
            $segments = SegmentGenerator::generate(
                $sessionType,
                $s->phase,
                $isMarathonDistance,
                $isPrimaryEasy,
                $longRunKm,
                $multiplier,
                $paces,
                $volumeScale,
            );
            // The headline figure is the CORE work only (never null, doesn't
            // need a VDOT estimate) — warmup/cooldown are additional minutes
            // on top, not part of what this number has ever meant.
            $distanceKm = round(SegmentGenerator::coreKmFor($sessionType, $isPrimaryEasy, $longRunKm, $multiplier) * $volumeScale, 1);
        }

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
            'clamp_note' => $isToday ? ($clamp['note'] ?? null) : null,
        ];
    }
}
