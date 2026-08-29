<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use Illuminate\Support\Carbon;

/**
 * Turns one week's phase + session count into a concrete row per calendar
 * day (Monday-Sunday), skipping any date the caller reports as pinned so the
 * periodizer never overwrites a user-fixed day (see {@see Periodizer}).
 *
 * Only ever decides `session_type` here — no km, pace or segment structure
 * enters generation at all. {@see SegmentGenerator} derives the full
 * warmup/main/cooldown breakdown fresh at render time from `session_type`
 * alone (plus phase, current baseline and paces), so a token like "the
 * week's first Easy day is bigger" is recomputed from sibling context by the
 * render-time caller rather than decided here (see
 * `docs/features/plan-periodizer.md`).
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
    public const float MARATHON_DISTANCE_THRESHOLD_M = 30_000.0;

    /** Ceiling on quality sessions per week once race-pace feedback asks for more. */
    private const int MAX_QUALITY_SLOTS = 3;

    /** A week with fewer sessions than this has no room to absorb an extra quality day. */
    private const int MIN_SESSIONS_FOR_EXTRA_QUALITY = 5;

    /**
     * @param  array<string, true>  $pinnedDates  Y-m-d dates already fixed by the user; never assigned a row here
     * @param  Carbon  $notBefore  dates earlier than this (a past day within the current week) are skipped too —
     *                             regeneration only ever writes today-forward, so past days stay untouched
     * @param  int  $qualityDelta  race-pace feedback from {@see PlanAdapter}: +1 adds a quality session, -1 drops one
     * @return array<string, array{phase: PlanPhase, session_type: SessionType}> keyed by Y-m-d
     */
    public function build(
        Carbon $weekStart,
        PlanPhase $phase,
        int $sessionsPerWeek,
        array $pinnedDates,
        ?float $raceDistanceM,
        bool $selfScaled,
        ?Carbon $notBefore = null,
        int $qualityDelta = 0,
    ): array {
        $sessionsPerWeek = max(self::MIN_SESSIONS, min(self::MAX_SESSIONS, $sessionsPerWeek));
        $trainingOffsets = self::DAY_TEMPLATES[$sessionsPerWeek];
        $longOffset = end($trainingOffsets);
        $isMarathonDistance = self::isMarathonDistance($raceDistanceM);

        $qualitySlots = $this->qualitySlots($phase, $sessionsPerWeek, $isMarathonDistance, $selfScaled, $qualityDelta);

        // Non-long training offsets, in date order — the pool quality work is
        // spread across. Picking spread-out positions (not just the first N)
        // keeps two hard sessions from landing on consecutive training days.
        $nonLongOffsets = array_values(array_diff($trainingOffsets, [$longOffset]));
        $qualityOffsets = array_flip(self::spreadOffsets($nonLongOffsets, count($qualitySlots)));

        $rows = [];
        $qualityIndex = 0;

        foreach (range(0, 6) as $offset) {
            $dayDate = $weekStart->copy()->addDays($offset);
            $date = $dayDate->toDateString();
            if (isset($pinnedDates[$date]) || ($notBefore !== null && $dayDate->lt($notBefore))) {
                continue;
            }

            if (! in_array($offset, $trainingOffsets, true)) {
                $rows[$date] = ['session_type' => SessionType::Rest];

                continue;
            }

            if ($offset === $longOffset) {
                $rows[$date] = ['session_type' => SessionType::Long];

                continue;
            }

            if (isset($qualityOffsets[$offset])) {
                $rows[$date] = $qualitySlots[$qualityIndex];
                $qualityIndex++;

                continue;
            }

            $rows[$date] = ['session_type' => SessionType::Easy];
        }

        return array_map(
            static fn (array $row): array => [...$row, 'phase' => $phase],
            $rows,
        );
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
     * Whether a race is long enough to earn race-pace-specific (Marathon
     * band) quality work in Peak/Taper — shared with render-time callers
     * ({@see \App\Http\Controllers\PlanController}, {@see CurrentWeekPlanBuilder})
     * so `SegmentGenerator::generate()` picks the same pace this class
     * decided the session structure with.
     */
    public static function isMarathonDistance(?float $raceDistanceM): bool
    {
        return $raceDistanceM !== null && $raceDistanceM >= self::MARATHON_DISTANCE_THRESHOLD_M;
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
        return count($this->qualitySlots($phase, $sessionsPerWeek, self::isMarathonDistance($raceDistanceM), $selfScaled, 0));
    }

    /**
     * @return list<array{session_type: SessionType}>
     */
    private function qualitySlots(PlanPhase $phase, int $sessionsPerWeek, bool $isMarathonDistance, bool $selfScaled, int $qualityDelta): array
    {
        return self::withQualityDelta(
            $this->phaseQualitySlots($phase, $sessionsPerWeek, $isMarathonDistance, $selfScaled),
            $phase,
            $sessionsPerWeek,
            $qualityDelta,
        );
    }

    /**
     * Race-pace feedback resizes the week's quality block. Deload and Taper
     * are exempt in both directions: neither exists to carry quality work,
     * and a taper's whole job is arriving fresh. Adding is further gated on
     * the week having enough sessions to absorb it, so a 3-day week never
     * turns into two-thirds quality.
     *
     * @param  list<array{session_type: SessionType}>  $slots
     * @return list<array{session_type: SessionType}>
     */
    private static function withQualityDelta(array $slots, PlanPhase $phase, int $sessionsPerWeek, int $qualityDelta): array
    {
        if ($qualityDelta === 0 || in_array($phase, [PlanPhase::Deload, PlanPhase::Taper], true)) {
            return $slots;
        }

        $ceiling = $sessionsPerWeek >= self::MIN_SESSIONS_FOR_EXTRA_QUALITY ? self::MAX_QUALITY_SLOTS : count($slots);
        $target = max(0, min($ceiling, count($slots) + $qualityDelta));

        if ($target <= count($slots)) {
            return array_slice($slots, 0, $target);
        }

        return [
            ...$slots,
            ...array_fill(0, $target - count($slots), ['session_type' => SessionType::Tempo]),
        ];
    }

    /**
     * @return list<array{session_type: SessionType}>
     */
    private function phaseQualitySlots(PlanPhase $phase, int $sessionsPerWeek, bool $isMarathonDistance, bool $selfScaled): array
    {
        if ($phase === PlanPhase::Deload) {
            return [];
        }

        if ($phase === PlanPhase::Base) {
            // "Predominantly easy, at most one threshold session" — and only
            // once there's a session to spare beyond the long run + 2 easy days.
            return $sessionsPerWeek >= 4
                ? [['session_type' => SessionType::Tempo]]
                : [];
        }

        // Peak/Taper for a marathon-distance race: one race-pace-specific
        // session, not the threshold/interval mix below. Still a Tempo slot
        // — {@see SegmentGenerator} is what recognises the marathon-pace
        // case (phase + race distance) and swaps its main-set pace.
        if (in_array($phase, [PlanPhase::Peak, PlanPhase::Taper], true) && $isMarathonDistance) {
            return [['session_type' => SessionType::Tempo]];
        }

        $count = $sessionsPerWeek <= 4 ? 1 : 2;
        $slots = [['session_type' => SessionType::Tempo]];
        if ($count === 2) {
            // Self-scaled Build stays threshold-only ("1-2 threshold sessions");
            // race-oriented Build/Peak/Taper mix in interval work.
            $slots[] = $selfScaled
                ? ['session_type' => SessionType::Tempo]
                : ['session_type' => SessionType::Interval];
        }

        return $slots;
    }
}
