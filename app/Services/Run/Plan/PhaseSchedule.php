<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Pure week-by-week phase allocation — the periodizer's phase-boundary math,
 * grounded in standard periodization practice (see the "Periodizer algorithm"
 * section of the Slice 6 plan and `docs/features/plan-periodizer.md`).
 *
 * Weeks are ISO (Monday-starting), keyed by their Monday date, so weekly
 * regeneration and mid-week on-demand regeneration agree on week boundaries.
 * {@see self::forRace()} always returns the FULL arc from its start week
 * through race week inclusive — callers slice however many weeks they
 * actually need. This keeps the base/build/peak ratios correct even when
 * only a lookahead window is materialized.
 *
 * The arc starts where the {@see \App\Models\Season} does, never at "today":
 * counting it from the current week put every week the athlete was actually
 * asked to train at index 0, so Build, Peak and the scheduled Deload were
 * only ever previewed. See `docs/decisions/the-arc-is-anchored-once.md`.
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

    /** Ceiling on how far the compounding {@see self::BUILD_WEEKLY_RAMP} may climb over a long arc. */
    private const float MAX_BUILD_MULTIPLIER = 1.4;

    /** Self-scaled deload volume reduction off the just-completed build block. */
    private const float DELOAD_REDUCTION = 0.35;

    /** Taper reduction curve, nearest-to-race last (a 3-week taper: -20% / -40% / -60%). */
    private const array TAPER_REDUCTION_CURVE = [0.20, 0.40, 0.60];

    /**
     * Every fourth week of the Base/Build ramp is a recovery week. Without one
     * the ramp compounds unbroken — five Build weeks at
     * {@see self::BUILD_WEEKLY_RAMP} is +33% with nothing absorbing it — and
     * the only Deload that existed was the reactive one
     * {@see \App\Services\Run\Plan\Periodizer::sliceFromCurrentWeek()} applies
     * to the current week once monotony, strain or adherence has ALREADY
     * slipped. The self-scaled arc has had a scheduled down week all along
     * ({@see self::SELF_SCALED_CYCLE_WEEKS}); the arc with a deadline is the
     * one that needed it more.
     */
    private const int DELOAD_EVERY_WEEKS = 4;

    private const int SELF_SCALED_CYCLE_WEEKS = 4;

    /** How many weeks the race block holds, race week included, up to and past the marathon threshold. */
    private const int BLOCK_WEEKS = 16;

    private const int LONG_RACE_BLOCK_WEEKS = 20;

    /** A week before block open, holding the self-scaled cycle. */
    public const string ZONE_GENERAL = 'general';

    /** A week inside the race block, periodized toward race day. */
    public const string ZONE_BLOCK = 'block';

    public function taperWeeksForDistance(float $distanceM): int
    {
        return match (true) {
            $distanceM <= self::HALF_MARATHON_DISTANCE_M => 1,
            $distanceM <= self::MARATHON_THRESHOLD_DISTANCE_M => 2,
            default => 3,
        };
    }

    /**
     * The Monday the race block opens. A computed date, never a season boundary:
     * see `docs/decisions/the-block-opens-on-a-computed-date.md`.
     */
    public static function blockOpensOn(Carbon $raceDate, float $raceDistanceM): Carbon
    {
        return $raceDate->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(
            ($raceDistanceM <= self::MARATHON_THRESHOLD_DISTANCE_M ? self::BLOCK_WEEKS : self::LONG_RACE_BLOCK_WEEKS) - 1,
        );
    }

    /**
     * The self-scaled cycle from the arc's start until the block opens, then
     * the race periodization over the block alone.
     *
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string}>
     */
    public function forRace(Carbon $arcStart, Carbon $raceDate, float $raceDistanceM): array
    {
        $arcStartWeek = $arcStart->copy()->startOfWeek(Carbon::MONDAY);
        $blockStart = self::blockOpensOn($raceDate, $raceDistanceM);
        if ($blockStart->lessThanOrEqualTo($arcStartWeek)) {
            return $this->raceBlock($arcStartWeek, $raceDate, $raceDistanceM);
        }

        return [
            ...$this->selfScaled($arcStartWeek, (int) $arcStartWeek->diffInWeeks($blockStart)),
            ...$this->raceBlock($blockStart, $raceDate, $raceDistanceM),
        ];
    }

    /**
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string}>
     */
    private function raceBlock(Carbon $currentWeekStart, Carbon $raceDate, float $raceDistanceM): array
    {
        $raceWeekStart = $raceDate->copy()->startOfWeek(Carbon::MONDAY);
        // diffInWeeks is signed, so a race day already behind us counts down
        // past zero. Floored at one week: `plan:close-finished-races` retires a
        // finished race long before this could matter, but four callers reach
        // this method and a negative count used to reach array_fill() below and
        // throw — taking every athlete after the thrower in the same
        // `plan:regenerate` run with it.
        $weeksToRace = max(1, (int) $currentWeekStart->diffInWeeks($raceWeekStart) + 1);

        $taperWeeks = $this->taperWeeksForDistance($raceDistanceM);

        // Too little time to build anything meaningful: taper for however many
        // weeks are actually left, prioritizing race-day freshness.
        if ($weeksToRace <= $taperWeeks + 1) {
            $phases = array_fill(0, $weeksToRace, PlanPhase::Taper);

            return $this->weeksFrom($currentWeekStart, $phases, self::ZONE_BLOCK);
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

        return $this->weeksFrom($currentWeekStart, self::withScheduledDeloads($phases, $baseWeeks + $buildWeeks), self::ZONE_BLOCK);
    }

    /**
     * `$opensWithRecovery` puts a single recovery week at the head of the arc,
     * before the cycle starts, for an athlete who has just raced. The cycle
     * then runs from the week after it, so the recovery week is an extra week
     * rather than one borrowed from the first build block.
     *
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string}>
     */
    public function selfScaled(Carbon $arcStart, int $weeks, bool $opensWithRecovery = false): array
    {
        $currentWeekStart = $arcStart->copy()->startOfWeek(Carbon::MONDAY);

        $phases = $opensWithRecovery ? [PlanPhase::Deload] : [];
        $cycleWeeks = $weeks - count($phases);
        for ($i = 0; $i < $cycleWeeks; $i++) {
            $cyclePosition = $i % self::SELF_SCALED_CYCLE_WEEKS;
            $phases[] = $cyclePosition < self::SELF_SCALED_CYCLE_WEEKS - 1 ? PlanPhase::Build : PlanPhase::Deload;
        }

        return $this->weeksFrom($currentWeekStart, $phases, self::ZONE_GENERAL);
    }

    /**
     * The weekly volume multiplier for an ordered phase sequence, relative to
     * whatever baseline weekly volume the caller supplies it against. Pure and
     * stored-data-driven: works equally against a freshly computed arc
     * (generation) or a sequence read back from stored rows (render), so a
     * render-time recompute never drifts from what was actually generated.
     *
     * A `$selfScaled` arc holds flat at 1.0 (dipping only for deload) rather
     * than ramping on top of a baseline that already tracks real volume. See
     * `docs/decisions/a-goalless-arc-does-not-ramp.md`. So does every
     * {@see self::ZONE_GENERAL} week in `$zones`, and the ramp after them counts
     * from the block's first week.
     *
     * @param  list<PlanPhase>  $phases
     * @param  list<string>  $zones
     * @return list<float>
     */
    public static function volumeMultipliers(array $phases, bool $selfScaled = false, array $zones = []): array
    {
        $generalWeeks = 0;
        while (($zones[$generalWeeks] ?? null) === self::ZONE_GENERAL) {
            $generalWeeks++;
        }
        if (in_array(self::ZONE_GENERAL, array_slice($zones, $generalWeeks), true)) {
            throw new InvalidArgumentException('General weeks must all precede the block.');
        }
        if ($generalWeeks === 0 || $selfScaled) {
            return self::arcMultipliers($phases, $selfScaled);
        }

        return [
            ...self::arcMultipliers(array_slice($phases, 0, $generalWeeks), true),
            ...self::arcMultipliers(array_slice($phases, $generalWeeks), false),
        ];
    }

    /**
     * @param  list<PlanPhase>  $phases
     * @return list<float>
     */
    private static function arcMultipliers(array $phases, bool $selfScaled): array
    {
        $result = [];
        // The ramp counts BUILD WEEKS, not position within a contiguous run: a
        // recovery week splits the build into separate runs, and exponentiating
        // within each run would restart every one of them at 1.0 and flatten the
        // progression entirely. Counting weeks lets the ramp carry across the dip.
        $buildWeeks = 0;
        $i = 0;
        $n = count($phases);

        while ($i < $n) {
            $phase = $phases[$i];
            $runLength = 1;
            while ($i + $runLength < $n && $phases[$i + $runLength] === $phase) {
                $runLength++;
            }

            $buildLevel = $buildWeeks === 0 ? 1.0 : self::rampLevel($buildWeeks - 1, $selfScaled);

            $curve = match ($phase) {
                PlanPhase::Base => array_fill(0, $runLength, 1.0),
                PlanPhase::Build => array_map(
                    static fn (int $k): float => self::rampLevel($buildWeeks + $k, $selfScaled),
                    range(0, $runLength - 1),
                ),
                PlanPhase::Peak => array_fill(0, $runLength, $buildLevel),
                PlanPhase::Taper => array_map(
                    static fn (float $reduction): float => $buildLevel * (1 - $reduction),
                    self::taperCurve($runLength),
                ),
                PlanPhase::Deload => array_fill(0, $runLength, $buildLevel * (1 - self::DELOAD_REDUCTION)),
            };

            if ($phase === PlanPhase::Build) {
                $buildWeeks += $runLength;
            }

            array_push($result, ...$curve);
            $i += $runLength;
        }

        return $result;
    }

    /** Where the ramp stands after `$buildWeeks` completed Build weeks. */
    private static function rampLevel(int $buildWeeks, bool $selfScaled): float
    {
        return $selfScaled
            ? 1.0
            : min(self::MAX_BUILD_MULTIPLIER, self::BUILD_WEEKLY_RAMP ** $buildWeeks);
    }

    /**
     * Turns every fourth week of the Base/Build ramp into a recovery week. Peak
     * and Taper are untouched, and a race arc short enough to hold fewer than
     * {@see self::DELOAD_EVERY_WEEKS} ramp weeks has nothing to recover from yet.
     * A recovery week never takes the ramp's last week: it moves one week
     * earlier, so the block reaches Peak off a Build week.
     *
     * @param  list<PlanPhase>  $phases
     * @param  int  $rampWeeks  how many leading weeks are Base or Build
     * @return list<PlanPhase>
     */
    private static function withScheduledDeloads(array $phases, int $rampWeeks): array
    {
        for ($i = self::DELOAD_EVERY_WEEKS - 1; $i < $rampWeeks; $i += self::DELOAD_EVERY_WEEKS) {
            $phases[$i === $rampWeeks - 1 ? $i - 1 : $i] = PlanPhase::Deload;
        }

        return array_values($phases);
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
     * @return list<array{week_start: Carbon, phase: PlanPhase, zone: string}>
     */
    private function weeksFrom(Carbon $firstWeekStart, array $phases, string $zone): array
    {
        $weeks = [];
        foreach ($phases as $index => $phase) {
            $weeks[] = [
                'week_start' => $firstWeekStart->copy()->addWeeks($index),
                'phase' => $phase,
                'zone' => $zone,
            ];
        }

        return $weeks;
    }
}
