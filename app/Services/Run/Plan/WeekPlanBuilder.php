<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\RaceSupport;
use App\Enums\SessionType;
use Illuminate\Support\Carbon;

/**
 * Turns one week's phase + session count into a concrete row per calendar
 * day (Monday-Sunday), skipping fixed dates so the periodizer never overwrites
 * a user-fixed or settled day (see {@see Periodizer}).
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
        2 => [2, 5],
        3 => [1, 3, 5],
        4 => [1, 3, 5, 6],
        5 => [0, 1, 3, 5, 6],
        6 => [0, 1, 2, 3, 5, 6],
    ];

    private const int MIN_SESSIONS = 2;

    private const int MIN_SESSIONS_FOR_QUALITY = 3;

    /**
     * A projected race under this is run above threshold, so VO2max work is the
     * specific stimulus. Over {@see self::THRESHOLD_RACE_SECONDS} the race is at
     * or below threshold and threshold work is more specific than intervals —
     * the same 10K is a different event for a 35-minute runner and a 70-minute
     * one, which race DISTANCE alone cannot see.
     */
    private const int VO2MAX_RACE_SECONDS = 3000;

    private const int THRESHOLD_RACE_SECONDS = 4200;

    private const int MAX_SESSIONS = 6;

    /** A week with fewer sessions than this carries one quality day on the phase baseline, not two. */
    private const int MIN_SESSIONS_FOR_EXTRA_QUALITY = 5;

    /**
     * @param  array<string, true>  $fixedDates  Y-m-d dates already fixed or settled; never assigned a row here
     * @param  Carbon  $notBefore  dates earlier than this (a past day within the current week) are skipped too —
     *                             regeneration only ever writes today-forward, so past days stay untouched
     * @param  int  $qualityDelta  the adapter's verdict on this week's quality block: -1 drops a session, 0 leaves the block alone
     * @param  ?list<int>  $preferredOffsets  an explicit {@see \App\Models\TrainingPreference} `run_days`
     *                                        (0=Mon..6=Sun) — when set (with `$preferredLongOffset`), replaces
     *                                        `DAY_TEMPLATES` entirely for this week rather than merely seeding it
     * @param  ?int  $preferredLongOffset  the matching `long_run_day`, always a member of `$preferredOffsets`
     * @param  ?Carbon  $raceDate  the active race's day — reshapes the week it falls in, see {@see self::raceWeekType()}
     * @param  string  $zone  {@see PhaseSchedule::ZONE_GENERAL}/{@see PhaseSchedule::ZONE_BLOCK} — a race
     *                        season's general-zone week trains its quality block by base rules
     *                        regardless of `$phase`, see {@see self::phaseQualitySlots()}
     * @return array<string, array{phase: PlanPhase, session_type: SessionType}> keyed by Y-m-d
     */
    public function build(
        Carbon $weekStart,
        PlanPhase $phase,
        int $sessionsPerWeek,
        array $fixedDates,
        ?float $raceDistanceM,
        bool $selfScaled,
        ?Carbon $notBefore = null,
        int $qualityDelta = 0,
        ?array $preferredOffsets = null,
        ?int $preferredLongOffset = null,
        ?float $projectedRaceSeconds = null,
        ?Carbon $raceDate = null,
        string $zone = PhaseSchedule::ZONE_BLOCK,
        bool $twoRunQualityEligible = false,
    ): array {
        if ($preferredOffsets !== null && $preferredLongOffset !== null) {
            $trainingOffsets = $preferredOffsets;
            $longOffset = $preferredLongOffset;
            $sessionsPerWeek = count($trainingOffsets);
        } else {
            $sessionsPerWeek = max(self::MIN_SESSIONS, min(self::MAX_SESSIONS, $sessionsPerWeek));
            $trainingOffsets = self::DAY_TEMPLATES[$sessionsPerWeek];
            $longOffset = end($trainingOffsets);
        }
        $isMarathonDistance = self::isMarathonDistance($raceDistanceM);

        // Non-long training offsets, in date order — the pool quality work is
        // spread across. Picking spread-out positions (not just the first N)
        // keeps two hard sessions from landing on consecutive training days.
        $nonLongOffsets = array_values(array_diff($trainingOffsets, [$longOffset]));
        $qualityPool = self::awayFromLongRun($nonLongOffsets, $longOffset);
        $qualityPool = array_values(array_filter(
            $qualityPool,
            static fn (int $offset): bool => self::raceWeekType($weekStart->copy()->addDays($offset), $raceDate) === null,
        ));

        $qualitySlots = self::withQualityDelta(
            $this->phaseQualitySlots($phase, $sessionsPerWeek, $isMarathonDistance, $selfScaled, $projectedRaceSeconds, $zone),
            $phase,
            $qualityDelta,
            $selfScaled,
            $zone,
        );
        if ($sessionsPerWeek === 2 && $twoRunQualityEligible && in_array($phase, [PlanPhase::Base, PlanPhase::Build, PlanPhase::Peak], true) && $qualityDelta >= 0) {
            $qualitySlots = [['session_type' => SessionType::Tempo]];
        }
        $selectedQualityOffsets = self::spreadOffsets($qualityPool, count($qualitySlots), $longOffset);
        $qualitySlots = array_slice($qualitySlots, 0, count($selectedQualityOffsets));
        $qualityByOffset = [];
        foreach ($selectedQualityOffsets as $index => $offset) {
            $qualityByOffset[$offset] = $qualitySlots[$index];
        }

        $rows = [];

        foreach (range(0, 6) as $offset) {
            $dayDate = $weekStart->copy()->addDays($offset);
            $date = $dayDate->toDateString();
            if (isset($fixedDates[$date]) || ($notBefore !== null && $dayDate->lt($notBefore))) {
                continue;
            }

            $raceWeekType = self::raceWeekType($dayDate, $raceDate);
            if ($raceWeekType !== null) {
                $rows[$date] = ['session_type' => $raceWeekType];

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

            if (isset($qualityByOffset[$offset])) {
                $rows[$date] = $qualityByOffset[$offset];

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
     * What race day and the days around it get, or null for any day the race
     * has no claim on.
     *
     * The template knows nothing about race day, so before this the week the
     * athlete's goal race falls in was laid out as an ordinary tapered week:
     * a Sunday marathon got a long run on the Saturday before it and `rest`
     * on the day itself, and a Tuesday 10K got a tempo session ON the race.
     * Race day is the session; the day before it is rest, and so is every day
     * after it, since the arc ends here and nothing is worth prescribing
     * between a goal race and the fresh plan that follows it.
     */
    private static function raceWeekType(Carbon $dayDate, ?Carbon $raceDate): ?SessionType
    {
        if ($raceDate === null) {
            return null;
        }

        $raceDay = $raceDate->copy()->startOfDay();

        return match (true) {
            $dayDate->isSameDay($raceDay) => SessionType::Race,
            $dayDate->gt($raceDay) => SessionType::Rest,
            $dayDate->isSameDay($raceDay->copy()->subDay()) => SessionType::Rest,
            default => null,
        };
    }

    /**
     * Removes the days next to the long run from the quality pool, even when
     * that leaves no room for quality.
     * Adjacency wraps the week, since Sunday's long run and next Monday are consecutive days.
     *
     * @param  list<int>  $nonLongOffsets
     * @return list<int>
     */
    private static function awayFromLongRun(array $nonLongOffsets, int $longOffset): array
    {
        $flanks = [($longOffset + 6) % 7, ($longOffset + 1) % 7];

        return array_values(array_diff($nonLongOffsets, $flanks));
    }

    /**
     * @param list<int> $offsets
     * @return list<int>
     */
    private static function spreadOffsets(array $offsets, int $count, int $longOffset): array
    {
        if ($count <= 0 || $offsets === []) {
            return [];
        }

        if ($count === 1) {
            return [self::bestSpacedOffset($offsets, $longOffset)];
        }

        $bestPair = [];
        $bestScore = -1;
        for ($i = 0; $i < count($offsets); $i++) {
            for ($j = $i + 1; $j < count($offsets); $j++) {
                $left = $offsets[$i];
                $right = $offsets[$j];
                $qualityGap = self::circularDayDistance($left, $right);
                if ($qualityGap < 2) {
                    continue;
                }

                $score = min(
                    $qualityGap,
                    self::longRunRecoveryDays($left, $longOffset),
                    self::longRunRecoveryDays($right, $longOffset),
                );
                $pair = $left < $right ? [$left, $right] : [$right, $left];

                if ($score > $bestScore || ($score === $bestScore && self::prefersLaterPair($pair, $bestPair))) {
                    $bestPair = $pair;
                    $bestScore = $score;
                }
            }
        }

        return $bestPair !== [] ? $bestPair : [self::bestSpacedOffset($offsets, $longOffset)];
    }

    /** @param list<int> $offsets */
    private static function bestSpacedOffset(array $offsets, int $longOffset): int
    {
        usort($offsets, static fn (int $left, int $right): int =>
            self::longRunRecoveryDays($right, $longOffset) <=> self::longRunRecoveryDays($left, $longOffset)
            ?: $right <=> $left);

        return $offsets[0];
    }

    /**
     * @param array{int, int} $candidate
     * @param array{}|array{int, int} $current
     */
    private static function prefersLaterPair(array $candidate, array $current): bool
    {
        if ($current === []) {
            return true;
        }

        return $candidate[1] > $current[1]
            || ($candidate[1] === $current[1] && $candidate[0] > $current[0]);
    }

    private static function circularDayDistance(int $left, int $right): int
    {
        $distance = abs($left - $right);

        return min($distance, 7 - $distance);
    }

    /** Circular calendar-day distance between a training day and a Long day. */
    public static function longRunRecoveryDays(int $sessionOffset, int $longOffset): int
    {
        return self::circularDayDistance($sessionOffset, $longOffset);
    }

    /**
     * Whether a race is long enough to earn race-pace-specific (Marathon
     * band) quality work in Peak/Taper — shared with render-time callers
     * ({@see PlanPageAssembler}, {@see CurrentWeekPlanBuilder})
     * so `SegmentGenerator::generate()` picks the same pace this class
     * decided the session structure with.
     */
    public static function isMarathonDistance(?float $raceDistanceM): bool
    {
        return RaceSupport::isMarathonClass($raceDistanceM);
    }

    /**
     * Whether this week is a race season's general zone (before the block
     * opens) rather than self-scaled training, which is `zone: general`
     * throughout and unaffected by this distinction.
     */
    private static function isGeneralZone(bool $selfScaled, string $zone): bool
    {
        return ! $selfScaled && $zone === PhaseSchedule::ZONE_GENERAL;
    }

    /**
     * How many quality (Tempo/Interval) sessions a week of this phase carries
     * — the same count {@see self::withQualityDelta()} materializes into rows,
     * exposed so season-goal generation can sum it across an arc without
     * materializing rows that far ahead. Computes `isMarathonDistance` the
     * same way {@see self::build()} does, so callers pass the raw race
     * distance rather than duplicating the marathon-distance threshold.
     */
    public function qualitySlotCount(PlanPhase $phase, int $sessionsPerWeek, ?float $raceDistanceM, bool $selfScaled, string $zone = PhaseSchedule::ZONE_BLOCK): int
    {
        return count($this->phaseQualitySlots($phase, $sessionsPerWeek, self::isMarathonDistance($raceDistanceM), $selfScaled, null, $zone));
    }

    /**
     * The adapter's verdict can shrink the week's quality block: a week run
     * harder than it was written drops a session. Base, Deload and Taper are
     * exempt: none exists to carry quality work, a taper's whole job is
     * arriving fresh, and Base is defined as predominantly easy with at most
     * one threshold session. A race season's general-zone week is exempt for
     * the same reason — it trains by base rules, see
     * {@see self::phaseQualitySlots()} — regardless of which phase the
     * self-scaled mesocycle it's borrowing happens to land it on.
     *
     * @param  list<array{session_type: SessionType}>  $slots
     * @return list<array{session_type: SessionType}>
     */
    private static function withQualityDelta(array $slots, PlanPhase $phase, int $qualityDelta, bool $selfScaled, string $zone): array
    {
        if ($qualityDelta >= 0 || self::isGeneralZone($selfScaled, $zone) || in_array($phase, [PlanPhase::Base, PlanPhase::Deload, PlanPhase::Taper], true)) {
            return $slots;
        }

        return array_slice($slots, 0, max(0, count($slots) + $qualityDelta));
    }

    /**
     * A week with only one quality slot spends it on whatever the phase
     * actually calls for, rather than always threshold: Base builds with a
     * Tempo, and a race-oriented Build/Peak/Taper for a sub-marathon race
     * gets the Interval work that moves race pace at those distances. Only a
     * second slot can hold both, and that needs
     * {@see self::MIN_SESSIONS_FOR_EXTRA_QUALITY} sessions to absorb it.
     * Self-scaled training stays threshold-only throughout — there is no race
     * pace to sharpen for, and its spec reads "1-2 threshold sessions". A race
     * season's general-zone week (before the block opens) trains by these
     * same Base rules whatever phase the self-scaled mesocycle assigned it —
     * race-specific interval work waits for the block.
     *
     * @return list<array{session_type: SessionType}>
     */
    private function phaseQualitySlots(PlanPhase $phase, int $sessionsPerWeek, bool $isMarathonDistance, bool $selfScaled, ?float $projectedRaceSeconds, string $zone = PhaseSchedule::ZONE_BLOCK): array
    {
        if ($phase === PlanPhase::Deload || $sessionsPerWeek < self::MIN_SESSIONS_FOR_QUALITY) {
            return [];
        }

        if ($phase === PlanPhase::Base || self::isGeneralZone($selfScaled, $zone)) {
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
        if (in_array($phase, [PlanPhase::Build, PlanPhase::Peak, PlanPhase::Taper], true) && $isMarathonDistance) {
            return [['session_type' => SessionType::Tempo]];
        }

        if ($sessionsPerWeek < self::MIN_SESSIONS_FOR_EXTRA_QUALITY) {
            return [['session_type' => self::singleQualityType($phase, $selfScaled, $projectedRaceSeconds)]];
        }

        // Two slots hold both stimuli, so there is nothing to choose between.
        return [
            ['session_type' => SessionType::Tempo],
            ['session_type' => $selfScaled ? SessionType::Tempo : SessionType::Interval],
        ];
    }

    /**
     * The one quality day a week gets when it only gets one. Self-scaled
     * training has no race pace to sharpen for and stays threshold-only. With a
     * race, the choice follows how long the race will take rather than how far
     * it is: a short race is run above threshold, a long one at or below it, and
     * in between the build develops VO2max while the peak and taper sharpen at
     * race-specific threshold. No projection yet means no evidence, so it falls
     * back to threshold — the safer single quality session, the same reasoning
     * Base already applies.
     */
    private static function singleQualityType(PlanPhase $phase, bool $selfScaled, ?float $projectedRaceSeconds): SessionType
    {
        if ($selfScaled || $projectedRaceSeconds === null) {
            return SessionType::Tempo;
        }

        return match (true) {
            $projectedRaceSeconds < self::VO2MAX_RACE_SECONDS => SessionType::Interval,
            $projectedRaceSeconds >= self::THRESHOLD_RACE_SECONDS => SessionType::Tempo,
            default => $phase === PlanPhase::Build ? SessionType::Interval : SessionType::Tempo,
        };
    }
}
