<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
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
     * @param  array{session_type: SessionType, distance_band: DistanceBand, pace_band: ?PaceBand, note: string}|null  $clamp
     * @param  array<string, DistanceBand>  $redistributed
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return array<string, mixed>
     */
    public static function dayPayload(
        PlannedSession $s,
        Carbon $today,
        ?array $clamp,
        array $redistributed,
        float $longRunKm,
        float $multiplier,
        ?array $paces,
        PlannedSessionStatus $status,
    ): array {
        $isToday = $s->date->isSameDay($today);

        $sessionType = ($isToday && $clamp !== null) ? $clamp['session_type'] : $s->session_type;
        $paceBand = ($isToday && $clamp !== null) ? $clamp['pace_band'] : $s->pace_band;

        $band = $s->distance_band;
        if ($isToday && $clamp !== null) {
            $band = $clamp['distance_band'];
        } elseif (isset($redistributed[$s->date->toDateString()])) {
            $band = $redistributed[$s->date->toDateString()];
        }

        return [
            'id' => $s->id,
            'date' => $s->date->toDateString(),
            'phase' => $s->phase->value,
            'session_type' => $sessionType->value,
            'distance_band' => $band->value,
            'pace_band' => $paceBand?->value,
            'pace_sec_per_km' => ($paceBand !== null && $paces !== null) ? $paces[$paceBand->value] : null,
            'distance_km' => DistanceBandKm::kmFor($band, $longRunKm, $multiplier),
            'pinned' => $s->pinned,
            'status' => $status->value,
            'clamp_note' => $isToday ? ($clamp['note'] ?? null) : null,
        ];
    }
}
