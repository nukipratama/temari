<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use Illuminate\Support\Carbon;

/**
 * Turns one week's phase + session count into a concrete row per calendar
 * day (Monday-Sunday), skipping any date the caller reports as pinned so the
 * periodizer never overwrites a user-fixed day (see {@see Periodizer}).
 *
 * `distance_band` is derived purely from `session_type` here (Long -> Long,
 * Tempo -> Medium, Interval -> Short, the week's first Easy day -> Medium and
 * the rest -> Short) — no km enters generation at all, per the "not frozen
 * into the row" design; see {@see DistanceBandKm} for the render-time
 * conversion.
 */
final class WeekPlanBuilder
{
    /**
     * Day-of-week offsets (0=Mon..6=Sun) that train, by session count. The
     * last offset in each template is always the week's long run.
     *
     * @var array<int, list<int>>
     */
    private const array DAY_TEMPLATES = [
        3 => [1, 3, 5],
        4 => [1, 3, 5, 6],
        5 => [0, 1, 3, 5, 6],
        6 => [0, 1, 2, 3, 5, 6],
    ];

    private const int MIN_SESSIONS = 3;

    private const int MAX_SESSIONS = 6;

    /** Races at/above this distance get race-pace-specific (marathon band) quality work in Peak/Taper. */
    private const float MARATHON_DISTANCE_THRESHOLD_M = 30_000.0;

    /**
     * @param  array<string, true>  $pinnedDates  Y-m-d dates already fixed by the user; never assigned a row here
     * @param  Carbon  $notBefore  dates earlier than this (a past day within the current week) are skipped too —
     *                             regeneration only ever writes today-forward, so past days stay untouched
     * @return array<string, array{phase: PlanPhase, session_type: SessionType, distance_band: DistanceBand, pace_band: ?PaceBand}> keyed by Y-m-d
     */
    public function build(
        Carbon $weekStart,
        PlanPhase $phase,
        int $sessionsPerWeek,
        array $pinnedDates,
        ?float $raceDistanceM,
        bool $selfScaled,
        ?Carbon $notBefore = null,
    ): array {
        $sessionsPerWeek = max(self::MIN_SESSIONS, min(self::MAX_SESSIONS, $sessionsPerWeek));
        $trainingOffsets = self::DAY_TEMPLATES[$sessionsPerWeek];
        $longOffset = end($trainingOffsets);
        $isMarathonDistance = $raceDistanceM !== null && $raceDistanceM >= self::MARATHON_DISTANCE_THRESHOLD_M;

        $qualitySlots = $this->qualitySlots($phase, $sessionsPerWeek, $isMarathonDistance, $selfScaled);
        $longSlot = $this->longSlot($phase, $isMarathonDistance);

        // Non-long training offsets, in date order — the pool quality work is
        // spread across. Picking spread-out positions (not just the first N)
        // keeps two hard sessions from landing on consecutive training days.
        $nonLongOffsets = array_values(array_diff($trainingOffsets, [$longOffset]));
        $qualityOffsets = array_flip(self::spreadOffsets($nonLongOffsets, count($qualitySlots)));

        $rows = [];
        $qualityIndex = 0;
        $easyBandFirstUsed = false;

        foreach (range(0, 6) as $offset) {
            $dayDate = $weekStart->copy()->addDays($offset);
            $date = $dayDate->toDateString();
            if (isset($pinnedDates[$date]) || ($notBefore !== null && $dayDate->lt($notBefore))) {
                continue;
            }

            if (! in_array($offset, $trainingOffsets, true)) {
                $rows[$date] = ['session_type' => SessionType::Rest, 'distance_band' => DistanceBand::Rest, 'pace_band' => null];

                continue;
            }

            if ($offset === $longOffset) {
                $rows[$date] = $longSlot;

                continue;
            }

            if (isset($qualityOffsets[$offset])) {
                $rows[$date] = $qualitySlots[$qualityIndex];
                $qualityIndex++;

                continue;
            }

            $band = $easyBandFirstUsed ? DistanceBand::Short : DistanceBand::Medium;
            $easyBandFirstUsed = true;
            $rows[$date] = ['session_type' => SessionType::Easy, 'distance_band' => $band, 'pace_band' => PaceBand::Easy];
        }

        return array_map(
            static fn (array $row): array => [...$row, 'phase' => $phase],
            $rows,
        );
    }

    /**
     * @return array{session_type: SessionType, distance_band: DistanceBand, pace_band: PaceBand}
     */
    private function longSlot(PlanPhase $phase, bool $isMarathonDistance): array
    {
        // A race-simulation long run at marathon pace, once the plan is close
        // enough to race day that race-pace-specific work makes sense.
        $paceBand = in_array($phase, [PlanPhase::Peak, PlanPhase::Taper], true) && $isMarathonDistance
            ? PaceBand::Marathon
            : PaceBand::Easy;

        return ['session_type' => SessionType::Long, 'distance_band' => DistanceBand::Long, 'pace_band' => $paceBand];
    }

    /**
     * Picks $count offsets out of $offsets, spread as evenly as possible, so
     * hard/quality sessions never land on two adjacent training days. Standard
     * periodization practice is at least one easy/recovery day between quality
     * sessions; the fixed day-of-week templates otherwise place quality work on
     * the first N training days, which can be back-to-back (e.g. Mon+Tue on the
     * 6-session template).
     *
     * @param  list<int>  $offsets
     * @return list<int>
     */
    private static function spreadOffsets(array $offsets, int $count): array
    {
        $available = count($offsets);
        if ($count <= 0 || $available === 0) {
            return [];
        }
        if ($count >= $available) {
            return $offsets;
        }
        if ($count === 1) {
            // A single quality day has no adjacency risk (nothing else hard to
            // clash with) — keep it on the first non-long training day, as before.
            return [$offsets[0]];
        }

        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $offsets[(int) round($i * ($available - 1) / ($count - 1))];
        }

        return array_values(array_unique($picked));
    }

    /**
     * How many quality (Tempo/Interval) sessions a week of this phase carries
     * — the same count {@see self::qualitySlots()} materializes into rows,
     * exposed so season-goal generation can sum it across an arc without
     * materializing rows that far ahead. Computes `isMarathonDistance` the
     * same way {@see self::build()} does, so callers pass the raw race
     * distance rather than duplicating the marathon-distance threshold.
     */
    public function qualitySlotCount(PlanPhase $phase, int $sessionsPerWeek, ?float $raceDistanceM, bool $selfScaled): int
    {
        $isMarathonDistance = $raceDistanceM !== null && $raceDistanceM >= self::MARATHON_DISTANCE_THRESHOLD_M;

        return count($this->qualitySlots($phase, $sessionsPerWeek, $isMarathonDistance, $selfScaled));
    }

    /**
     * @return list<array{session_type: SessionType, distance_band: DistanceBand, pace_band: PaceBand}>
     */
    private function qualitySlots(PlanPhase $phase, int $sessionsPerWeek, bool $isMarathonDistance, bool $selfScaled): array
    {
        if ($phase === PlanPhase::Deload) {
            return [];
        }

        if ($phase === PlanPhase::Base) {
            // "Predominantly easy, at most one threshold session" — and only
            // once there's a session to spare beyond the long run + 2 easy days.
            return $sessionsPerWeek >= 4
                ? [['session_type' => SessionType::Tempo, 'distance_band' => DistanceBand::Medium, 'pace_band' => PaceBand::Threshold]]
                : [];
        }

        // Peak/Taper for a marathon-distance race: one race-pace-specific
        // session, not the threshold/interval mix below.
        if (in_array($phase, [PlanPhase::Peak, PlanPhase::Taper], true) && $isMarathonDistance) {
            return [['session_type' => SessionType::Tempo, 'distance_band' => DistanceBand::Medium, 'pace_band' => PaceBand::Marathon]];
        }

        $count = $sessionsPerWeek <= 4 ? 1 : 2;
        $slots = [['session_type' => SessionType::Tempo, 'distance_band' => DistanceBand::Medium, 'pace_band' => PaceBand::Threshold]];
        if ($count === 2) {
            // Self-scaled Build stays threshold-only ("1-2 threshold sessions");
            // race-oriented Build/Peak/Taper mix in interval work.
            $slots[] = $selfScaled
                ? ['session_type' => SessionType::Tempo, 'distance_band' => DistanceBand::Medium, 'pace_band' => PaceBand::Threshold]
                : ['session_type' => SessionType::Interval, 'distance_band' => DistanceBand::Short, 'pace_band' => PaceBand::Interval];
        }

        return $slots;
    }
}
