<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use Illuminate\Support\Carbon;

/**
 * Pure week-by-week phase allocation — the periodizer's phase-boundary math,
 * grounded in standard periodization practice (see the "Periodizer algorithm"
 * section of the Slice 6 plan and `docs/features/plan-periodizer.md`).
 *
 * Weeks are ISO (Monday-starting), keyed by their Monday date, so weekly
 * regeneration and mid-week on-demand regeneration agree on week boundaries.
 * {@see self::forRace()} always returns the FULL arc from today's week
 * through race week inclusive — callers ({@see Periodizer} for writing,
 * {@see \App\Http\Controllers\PlanController} for rendering) slice however
 * many weeks they actually need. This keeps the base/build/peak ratios
 * correct even when only a lookahead window is materialized.
 */
final class PhaseSchedule
{
    /** race distance (m) -> taper length (weeks), standard taper-duration convention. */
    private const float HALF_MARATHON_DISTANCE_M = 15_000.0;

    private const float MARATHON_THRESHOLD_DISTANCE_M = 25_000.0;

    /**
     * base / build / peak split of the weeks remaining after the taper — Base
     * has no constant of its own since it's computed as the remainder (see
     * {@see self::forRace()}), which is what keeps the three always summing
     * to exactly $remainingWeeks; a literal 30% base fraction would leave a
     * fourth, unused rounding of the same weeks.
     */
    private const float BUILD_FRACTION = 0.45;

    private const float PEAK_FRACTION = 0.25;

    /** Weekly compounding ramp during Build, the midpoint of the 5-10% "10% rule" range. */
    private const float BUILD_WEEKLY_RAMP = 1.075;

    /** Peak's long run sits slightly below Build's peak volume. */
    private const float PEAK_VOLUME_FRACTION = 0.92;

    /** Self-scaled deload volume reduction off the just-completed build block. */
    private const float DELOAD_REDUCTION = 0.35;

    /** Taper reduction curve, nearest-to-race last (a 3-week taper: -20% / -40% / -60%). */
    private const array TAPER_REDUCTION_CURVE = [0.20, 0.40, 0.60];

    private const int SELF_SCALED_CYCLE_WEEKS = 4;

    public function taperWeeksForDistance(float $distanceM): int
    {
        return match (true) {
            $distanceM <= self::HALF_MARATHON_DISTANCE_M => 1,
            $distanceM <= self::MARATHON_THRESHOLD_DISTANCE_M => 2,
            default => 3,
        };
    }

    /**
     * @return list<array{week_start: Carbon, phase: PlanPhase}>
     */
    public function forRace(Carbon $today, Carbon $raceDate, float $raceDistanceM): array
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $raceWeekStart = $raceDate->copy()->startOfWeek(Carbon::MONDAY);
        $weeksToRace = (int) $currentWeekStart->diffInWeeks($raceWeekStart) + 1;

        $taperWeeks = $this->taperWeeksForDistance($raceDistanceM);

        // Too little time to build anything meaningful: taper for however many
        // weeks are actually left, prioritizing race-day freshness.
        if ($weeksToRace <= $taperWeeks + 1) {
            $phases = array_fill(0, $weeksToRace, PlanPhase::Taper);

            return $this->weeksFrom($currentWeekStart, $phases);
        }

        $remainingWeeks = $weeksToRace - $taperWeeks;

        // Peak and Build are each rounded from their own fraction of the
        // remaining weeks (floored at 1 apiece, since remainingWeeks >= 2 here);
        // Base absorbs whatever is left so the three always sum to exactly
        // remainingWeeks, with no overflow/underflow regardless of rounding.
        $peakWeeks = max(1, (int) round($remainingWeeks * self::PEAK_FRACTION));
        $buildWeeks = max(1, (int) round($remainingWeeks * self::BUILD_FRACTION));
        if ($peakWeeks + $buildWeeks > $remainingWeeks) {
            $buildWeeks = max(1, $remainingWeeks - $peakWeeks);
        }
        $baseWeeks = max(0, $remainingWeeks - $buildWeeks - $peakWeeks);

        $phases = [
            ...array_fill(0, $baseWeeks, PlanPhase::Base),
            ...array_fill(0, $buildWeeks, PlanPhase::Build),
            ...array_fill(0, $peakWeeks, PlanPhase::Peak),
            ...array_fill(0, $taperWeeks, PlanPhase::Taper),
        ];

        return $this->weeksFrom($currentWeekStart, $phases);
    }

    /**
     * @return list<array{week_start: Carbon, phase: PlanPhase}>
     */
    public function selfScaled(Carbon $today, int $weeks): array
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        $phases = [];
        for ($i = 0; $i < $weeks; $i++) {
            $cyclePosition = $i % self::SELF_SCALED_CYCLE_WEEKS;
            $phases[] = $cyclePosition < self::SELF_SCALED_CYCLE_WEEKS - 1 ? PlanPhase::Build : PlanPhase::Deload;
        }

        return $this->weeksFrom($currentWeekStart, $phases);
    }

    /**
     * The weekly volume multiplier for an ordered phase sequence, relative to
     * whatever baseline weekly volume the caller supplies it against. Pure and
     * stored-data-driven: works equally against a freshly computed arc
     * (generation) or a sequence read back from stored rows (render), so a
     * render-time recompute never drifts from what was actually generated.
     *
     * @param  list<PlanPhase>  $phases
     * @return list<float>
     */
    public static function volumeMultipliers(array $phases): array
    {
        $result = [];
        $prevBuildFinal = 1.0;
        $i = 0;
        $n = count($phases);

        while ($i < $n) {
            $phase = $phases[$i];
            $runLength = 1;
            while ($i + $runLength < $n && $phases[$i + $runLength] === $phase) {
                $runLength++;
            }

            $curve = match ($phase) {
                PlanPhase::Base => array_fill(0, $runLength, 1.0),
                PlanPhase::Build => array_map(
                    static fn (int $k): float => self::BUILD_WEEKLY_RAMP ** $k,
                    range(0, $runLength - 1),
                ),
                PlanPhase::Peak => array_fill(0, $runLength, $prevBuildFinal * self::PEAK_VOLUME_FRACTION),
                PlanPhase::Taper => array_map(
                    static fn (float $reduction): float => $prevBuildFinal * self::PEAK_VOLUME_FRACTION * (1 - $reduction),
                    self::taperCurve($runLength),
                ),
                PlanPhase::Deload => array_fill(0, $runLength, $prevBuildFinal * (1 - self::DELOAD_REDUCTION)),
            };

            if ($phase === PlanPhase::Build) {
                $prevBuildFinal = end($curve);
            }

            array_push($result, ...$curve);
            $i += $runLength;
        }

        return $result;
    }

    /**
     * The taper reduction curve sized to $weeks, nearest-to-race last. A
     * standard 3-week (or shorter) taper takes the trailing slice of the fixed
     * -20/-40/-60% curve; a longer taper-only arc (only reachable when the
     * race is very close relative to its own taper length) prepends milder
     * reductions so the curve still ends at -60% right before race day.
     *
     * @return list<float>
     */
    private static function taperCurve(int $weeks): array
    {
        $fixed = self::TAPER_REDUCTION_CURVE;
        if ($weeks <= count($fixed)) {
            return array_slice($fixed, count($fixed) - $weeks);
        }

        $extra = [];
        for ($i = $weeks - count($fixed); $i >= 1; $i--) {
            $extra[] = max(0.05, 0.20 - $i * 0.10);
        }

        return [...$extra, ...$fixed];
    }

    /**
     * @param  list<PlanPhase>  $phases
     * @return list<array{week_start: Carbon, phase: PlanPhase}>
     */
    private function weeksFrom(Carbon $firstWeekStart, array $phases): array
    {
        $weeks = [];
        foreach ($phases as $index => $phase) {
            $weeks[] = [
                'week_start' => $firstWeekStart->copy()->addWeeks($index),
                'phase' => $phase,
            ];
        }

        return $weeks;
    }
}
