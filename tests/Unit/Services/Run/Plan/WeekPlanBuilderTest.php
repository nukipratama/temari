<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Services\Run\Plan\PhaseSchedule;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\WeekPlanBuilder;
use Illuminate\Support\Carbon;

const WEEK_PACES = ['easy' => 380, 'marathon' => 320, 'threshold' => 292, 'interval' => 268];

beforeEach(function (): void {
    $this->builder = new WeekPlanBuilder();
    $this->monday = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
});

it('produces exactly one row per day, minus pinned and past-in-current-week dates', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false);

    expect($rows)->toHaveCount(7);
});

it('never assigns a row to a pinned date', function (): void {
    $pinned = $this->monday->copy()->addDays(1)->toDateString();

    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [$pinned => true], null, false);

    expect($rows)->not->toHaveKey($pinned)
        ->and($rows)->toHaveCount(6);
});

it('never assigns a row to a date before notBefore', function (): void {
    $notBefore = $this->monday->copy()->addDays(3);

    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false, $notBefore);

    foreach (array_keys($rows) as $date) {
        expect(Carbon::parse($date)->lt($notBefore))->toBeFalse();
    }
    expect($rows)->toHaveCount(4); // Thu..Sun
});

it('gives the last training day of the week the Long session type', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, false);
    // 4-session template: Tue, Thu, Sat, Sun -- Sun is the long day.
    $sunday = $this->monday->copy()->addDays(6)->toDateString();

    expect($rows[$sunday]['session_type'])->toBe(SessionType::Long);
});

it('marks every non-training day as Rest', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 3, [], null, false);
    // 3-session template: Tue, Thu, Sat. Monday is a rest day.
    $monday = $this->monday->toDateString();

    expect($rows[$monday]['session_type'])->toBe(SessionType::Rest);
});

it('Base phase stays quality-free below 4 sessions/week, adds one Tempo at 4+', function (): void {
    $withoutQuality = $this->builder->build($this->monday, PlanPhase::Base, 3, [], null, false);
    $withQuality = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false);

    expect(collect($withoutQuality)->pluck('session_type'))->not->toContain(SessionType::Tempo);
    expect(collect($withQuality)->pluck('session_type'))->toContain(SessionType::Tempo);
});

it('Build phase mixes Tempo and Interval once sessions/week exceeds 4, race-oriented', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], 42_195.0, false);
    $types = collect($rows)->pluck('session_type');

    expect($types)->toContain(SessionType::Tempo)
        ->and($types)->toContain(SessionType::Interval);
});

it('self-scaled Build stays threshold-only, never adds Interval, even at 2 quality slots', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true);
    $types = collect($rows)->pluck('session_type');

    expect($types)->not->toContain(SessionType::Interval);
});

it('Peak/Taper for a marathon-distance race narrows the quality block to a single race-pace-specific slot', function (): void {
    // Pace itself (Threshold vs Marathon) is SegmentGenerator's call now — see
    // its "switches a Tempo day to Marathon pace..." tests. What WeekPlanBuilder
    // still decides is the SLOT COUNT: one narrowed session, not the normal mix.
    $rows = $this->builder->build($this->monday, PlanPhase::Peak, 6, [], 42_195.0, false);

    expect(qualityCount($rows))->toBe(1);
});

it('Peak/Taper for a shorter race keeps the normal threshold/interval mix', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Peak, 6, [], 10_000.0, false);

    expect(qualityCount($rows))->toBe(2);
});

it('Deload carries no quality sessions at all', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Deload, 6, [], null, true);
    $types = collect($rows)->pluck('session_type')->unique()->values()->all();

    expect($types)->not->toContain(SessionType::Tempo)
        ->and($types)->not->toContain(SessionType::Interval);
});

it('clamps an out-of-range session count into the supported 2-6 template range', function (): void {
    $tooMany = $this->builder->build($this->monday, PlanPhase::Base, 10, [], null, false);
    $tooFew = $this->builder->build($this->monday, PlanPhase::Base, 1, [], null, false);

    expect($tooMany)->toHaveCount(7) // falls back to the 6-session template
        ->and($tooFew)->toHaveCount(7); // falls back to the 2-session template
});

it('supports the 2-session template, long run on Saturday', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 2, [], null, true);
    $saturday = $this->monday->copy()->addDays(5)->toDateString();

    // Two training days, and neither is quality — a week this short has no room
    // for it without giving up every easy kilometre.
    expect(collect($rows)->filter(fn (array $r): bool => $r['session_type'] !== SessionType::Rest))->toHaveCount(2)
        ->and(qualityCount($rows))->toBe(0)
        ->and($rows[$saturday]['session_type'])->toBe(SessionType::Long);
});

it('an explicit run_days/long_run_day preference overrides the day template entirely', function (): void {
    $friday = $this->monday->copy()->addDays(4)->toDateString();
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, true, null, 0, [0, 2, 4], 4);

    expect($rows[$friday]['session_type'])->toBe(SessionType::Long)
        ->and(collect($rows)->filter(fn (array $r): bool => $r['session_type'] !== SessionType::Rest))->toHaveCount(3);
});

it('tags every produced row with the phase it was built for', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Peak, 4, [], null, false);

    foreach ($rows as $row) {
        expect($row['phase'])->toBe(PlanPhase::Peak);
    }
});

function qualityCount(array $rows): int
{
    return collect($rows)
        ->filter(fn (array $r): bool => in_array($r['session_type'], [SessionType::Tempo, SessionType::Interval], true))
        ->count();
}

it('adds a quality session when race-pace feedback asks for more', function (): void {
    $before = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true);
    $after = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, null, 1);

    expect(qualityCount($before))->toBe(2)
        ->and(qualityCount($after))->toBe(2);
});

it('drops a quality session when race-pace feedback asks for less', function (): void {
    $before = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true);
    $after = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, null, -1);

    expect(qualityCount($before))->toBe(2)
        ->and(qualityCount($after))->toBe(1);
});

it('drops the week to zero quality when asked for less than it already carries', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, false, null, -1);

    expect(qualityCount($rows))->toBe(0);
});

it('leaves a Base week the one quality day its phase defines, in either direction', function (): void {
    $eased = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false, null, -1);
    $pushed = $this->builder->build($this->monday, PlanPhase::Base, 5, [], null, false, null, 1);

    expect(qualityCount($eased))->toBe(1)
        ->and(qualityCount($pushed))->toBe(1);
});

it('refuses to add a quality session to a week too short to absorb it', function (): void {
    // Three sessions is the long run plus two: a second quality day would
    // leave the week with no easy running at all.
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 3, [], null, true, null, 1);

    expect(qualityCount($rows))->toBe(1);
});

it('adds the promised quality session to a four-session week, either side of the long run', function (): void {
    $before = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, true);
    $after = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, true, null, 1);

    // 4-session template: Tue, Thu, Sat, Sun (long). Both quality days land
    // away from Sunday's long run rather than flanking it.
    $quality = collect($after)
        ->filter(fn (array $r): bool => in_array($r['session_type'], [SessionType::Tempo, SessionType::Interval], true))
        ->keys()
        ->map(fn (string $d): int => (int) Carbon::parse($d)->dayOfWeekIso)
        ->all();

    expect(qualityCount($before))->toBe(1)
        ->and(qualityCount($after))->toBe(2)
        ->and($quality)->toBe([2, 4]);
});

it('drops the quality session a four-session week already carries when feedback asks for less', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, true, null, -1);

    expect(qualityCount($rows))->toBe(0);
});

it('still empties a four-session race week\'s quality block when the week was run too hard', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], 10_000.0, false, null, -1, projectedRaceSeconds: 3469.0);

    expect(qualityCount($rows))->toBe(0);
});

it('never asks a week for more quality days than it has room to place', function (): void {
    // Five sessions: Mon, Tue, Thu, Sat, Sun (long). Saturday and Monday flank
    // the long run, leaving only Tue and Thu to carry quality work -- so the
    // week keeps the two it already had rather than being promised a third.
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 5, [], null, true, null, 1);

    expect(qualityCount($rows))->toBe(2);
});

it('never lets race-pace feedback add quality work to a taper or a deload', function (): void {
    $taper = $this->builder->build($this->monday, PlanPhase::Taper, 6, [], 10_000.0, false, null, 1);
    $deload = $this->builder->build($this->monday, PlanPhase::Deload, 6, [], null, true, null, 1);

    expect(qualityCount($taper))->toBe(2)
        ->and(qualityCount($deload))->toBe(0);
});

it('caps the quality block even when feedback keeps asking for more', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, null, 5);

    expect(qualityCount($rows))->toBe(2);
});

it('leaves the season-goal slot count on the unadapted phase baseline', function (): void {
    expect($this->builder->qualitySlotCount(PlanPhase::Build, 6, null, true))->toBe(2);
});

it('counts a race season\'s general-zone week at its base-rule slot count, not the block\'s', function (): void {
    expect($this->builder->qualitySlotCount(PlanPhase::Build, 6, 10_000.0, false, PhaseSchedule::ZONE_GENERAL))->toBe(1)
        ->and($this->builder->qualitySlotCount(PlanPhase::Build, 6, 10_000.0, false, PhaseSchedule::ZONE_BLOCK))->toBe(2);
});

it('classifies marathon distance at and above the threshold, never on a null race', function (): void {
    expect(WeekPlanBuilder::isMarathonDistance(null))->toBeFalse()
        ->and(WeekPlanBuilder::isMarathonDistance(21_097.5))->toBeFalse()
        ->and(WeekPlanBuilder::isMarathonDistance(30_000.0))->toBeTrue()
        ->and(WeekPlanBuilder::isMarathonDistance(42_195.0))->toBeTrue();
});

it('spends a fast runner\'s only quality day on interval work, and a slow runner\'s on threshold', function (): void {
    $tenK = 10_000.0;

    $types = fn (PlanPhase $phase, ?float $seconds): array => array_values(array_map(
        fn (array $row): SessionType => $row['session_type'],
        $this->builder->build($this->monday, $phase, 4, [], $tenK, false, null, 0, null, null, $seconds),
    ));

    // The same 10K is a different event depending on how long it takes. A
    // 35-minute runner races above threshold, so VO2max work is specific.
    $fast = 35 * 60.0;
    expect($types(PlanPhase::Build, $fast))->toContain(SessionType::Interval)
        ->and($types(PlanPhase::Peak, $fast))->toContain(SessionType::Interval);

    // A 70-minute runner races at or below threshold, so intervals train a pace
    // they will never race at.
    $slow = 70 * 60.0;
    expect($types(PlanPhase::Build, $slow))->toContain(SessionType::Tempo)
        ->and($types(PlanPhase::Build, $slow))->not->toContain(SessionType::Interval)
        ->and($types(PlanPhase::Peak, $slow))->not->toContain(SessionType::Interval);

    // In between, the build develops VO2max and the peak sharpens at threshold.
    $middling = 60 * 60.0;
    expect($types(PlanPhase::Build, $middling))->toContain(SessionType::Interval)
        ->and($types(PlanPhase::Peak, $middling))->not->toContain(SessionType::Interval);

    // Base is threshold whatever the runner, and Deload carries no quality.
    expect($types(PlanPhase::Base, $fast))->not->toContain(SessionType::Interval)
        ->and($types(PlanPhase::Deload, $fast))->not->toContain(SessionType::Interval);
});

it('falls back to threshold when there is no projection to judge the race by', function (): void {
    $types = array_column($this->builder->build($this->monday, PlanPhase::Build, 4, [], 10_000.0, false), 'session_type');

    expect($types)->toContain(SessionType::Tempo)
        ->and($types)->not->toContain(SessionType::Interval);
});

it('keeps quality off the days either side of the long run', function (): void {
    foreach ([5, 6] as $sessionsPerWeek) {
        $rows = $this->builder->build($this->monday, PlanPhase::Build, $sessionsPerWeek, [], 10_000.0, false, null, 0, null, null, 35 * 60.0);

        $offsetOf = fn (string $date): int => (int) $this->monday->diffInDays(Carbon::parse($date));
        $long = null;
        $quality = [];
        foreach ($rows as $date => $row) {
            if ($row['session_type'] === SessionType::Long) {
                $long = $offsetOf($date);
            }
            if (in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true)) {
                $quality[] = $offsetOf($date);
            }
        }

        expect($long)->not->toBeNull();
        foreach ($quality as $offset) {
            expect($offset)->not->toBe(($long + 1) % 7, "sessions={$sessionsPerWeek}")
                ->and($offset)->not->toBe(($long + 6) % 7, "sessions={$sessionsPerWeek}");
        }
    }
});

it('gives a two-session week easy running rather than half a week of quality', function (): void {
    foreach ([PlanPhase::Build, PlanPhase::Peak, PlanPhase::Taper] as $phase) {
        $types = array_column($this->builder->build($this->monday, $phase, 2, [], 10_000.0, false, null, 0, null, null, 35 * 60.0), 'session_type');

        expect($types)->not->toContain(SessionType::Tempo)
            ->and($types)->not->toContain(SessionType::Interval)
            ->and($types)->toContain(SessionType::Long);
    }
});

it('keeps a four-session week on threshold when there is no race to sharpen for', function (): void {
    foreach ([PlanPhase::Build, PlanPhase::Peak, PlanPhase::Taper] as $phase) {
        $types = array_column($this->builder->build($this->monday, $phase, 4, [], null, true), 'session_type');

        expect($types)->toContain(SessionType::Tempo)
            ->and($types)->not->toContain(SessionType::Interval);
    }
});

it('keeps a marathon build on race-pace tempo rather than swapping in intervals', function (): void {
    $marathon = 42_195.0;

    foreach ([PlanPhase::Peak, PlanPhase::Taper] as $phase) {
        $types = array_column($this->builder->build($this->monday, $phase, 4, [], $marathon, false), 'session_type');

        expect($types)->toContain(SessionType::Tempo)
            ->and($types)->not->toContain(SessionType::Interval);
    }
});

it('still gives a five-session week both a threshold and an interval day', function (): void {
    $types = array_column($this->builder->build($this->monday, PlanPhase::Build, 5, [], 10_000.0, false), 'session_type');

    expect($types)->toContain(SessionType::Tempo)
        ->and($types)->toContain(SessionType::Interval);
});

it('makes race day the race, and rests the day before it', function (): void {
    // Saturday of the built week.
    $raceDate = $this->monday->copy()->addDays(5);

    $rows = $this->builder->build($this->monday, PlanPhase::Taper, 4, [], 21_097.0, false, raceDate: $raceDate);

    expect($rows[$raceDate->toDateString()]['session_type'])->toBe(SessionType::Race)
        ->and($rows[$raceDate->copy()->subDay()->toDateString()]['session_type'])->toBe(SessionType::Rest);
});

it('trains nothing after race day, so a Sunday marathon gets no long run the day before it', function (): void {
    $raceDate = $this->monday->copy()->addDays(6);

    $rows = $this->builder->build($this->monday, PlanPhase::Taper, 5, [], 42_195.0, false, raceDate: $raceDate);

    expect($rows[$raceDate->copy()->subDay()->toDateString()]['session_type'])->toBe(SessionType::Rest)
        ->and(array_column($rows, 'session_type'))->not->toContain(SessionType::Long);
});

it('rests every day after race day rather than training through the days a goal race is recovered from', function (): void {
    // Tuesday: the layout that used to prescribe a tempo ON the race.
    $raceDate = $this->monday->copy()->addDay();

    $rows = $this->builder->build($this->monday, PlanPhase::Taper, 4, [], 10_000.0, false, raceDate: $raceDate);

    $afterRace = array_column(array_slice($rows, 2), 'session_type');

    expect($afterRace)->toHaveCount(5)
        ->and($afterRace)->each->toBe(SessionType::Rest);
});

it('leaves a week the race does not fall in completely alone', function (): void {
    $raceDate = $this->monday->copy()->addWeeks(3);

    $withRace = array_column($this->builder->build($this->monday, PlanPhase::Build, 4, [], 10_000.0, false, raceDate: $raceDate), 'session_type');
    $without = array_column($this->builder->build($this->monday, PlanPhase::Build, 4, [], 10_000.0, false), 'session_type');

    expect($withRace)->toBe($without)
        ->and($withRace)->not->toContain(SessionType::Race);
});

/**
 * Base is documented as "predominantly easy, at most one threshold session",
 * but only Deload and Taper were exempt from the quality delta, so a
 * `behind_race_pace` verdict appended a second Tempo and made Base harder
 * than Build: 8.0 km hard of 26.8 (30%) against Build's 6.1 of 24.9 (24%).
 */
it('holds Base at one quality session even when the adapter asks for more', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Base, 5, [], 10_000.0, false, null, 1);

    $quality = array_filter(
        array_column($rows, 'session_type'),
        fn (SessionType $type): bool => in_array($type, [SessionType::Tempo, SessionType::Interval], true),
    );

    expect($quality)->toHaveCount(1);
});

it('trains a race season\'s general-zone week by base rules: at most one quality slot, and it is a tempo', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], 10_000.0, false, zone: PhaseSchedule::ZONE_GENERAL);

    expect(qualityCount($rows))->toBe(1)
        ->and(collect($rows)->pluck('session_type'))->not->toContain(SessionType::Interval);
});

it('leaves a race season\'s block-zone week unchanged by the general-zone rule', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], 10_000.0, false, zone: PhaseSchedule::ZONE_BLOCK);

    expect(qualityCount($rows))->toBe(2)
        ->and(collect($rows)->pluck('session_type'))->toContain(SessionType::Interval);
});

it('does not let quality_delta push a general-zone week past one quality session', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], 10_000.0, false, null, 1, zone: PhaseSchedule::ZONE_GENERAL);

    expect(qualityCount($rows))->toBe(1);
});

it('leaves a self-scaled (goal-less) season unchanged by the general-zone rule', function (): void {
    $generalZone = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, zone: PhaseSchedule::ZONE_GENERAL);
    $blockZone = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, zone: PhaseSchedule::ZONE_BLOCK);

    expect(qualityCount($generalZone))->toBe(2)
        ->and(array_column($generalZone, 'session_type'))->toBe(array_column($blockZone, 'session_type'));
});

it('keeps a Base week under a fifth of its volume at threshold or faster', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Base, 5, [], 10_000.0, false, null, 1);

    $weekKm = 0.0;
    $hardKm = 0.0;
    foreach ($rows as $row) {
        foreach (SegmentGenerator::generate($row['session_type'], PlanPhase::Base, 10_000.0, false, 9.1, 1.0, INF, WEEK_PACES) as $segment) {
            $weekKm += $segment->km ?? 0.0;
            if (in_array($segment->paceLabel, [PaceBand::Threshold, PaceBand::Interval], true)) {
                $hardKm += $segment->km ?? 0.0;
            }
        }
    }

    expect($hardKm / $weekKm)->toBeLessThan(0.20);
});

/**
 * A build week already carrying tempo + interval used to be handed a SECOND
 * tempo, doubling the stimulus it had most of instead of the one it had least.
 */
it('alternates the stimulus when the adapter adds a quality day', function (): void {
    $qualityOf = fn (int $sessions, float $projectedRaceSeconds): array => array_count_values(array_map(
        fn (SessionType $type): string => $type->value,
        array_filter(
            array_column(
                $this->builder->build($this->monday, PlanPhase::Build, $sessions, [], 10_000.0, false, null, 1, projectedRaceSeconds: $projectedRaceSeconds),
                'session_type',
            ),
            fn (SessionType $type): bool => in_array($type, [SessionType::Tempo, SessionType::Interval], true),
        ),
    ));

    // One of each already, so the tie falls back to what the phase would pick
    // for a single quality day — Interval for a 35-minute 10K.
    expect($qualityOf(6, 35 * 60.0))->toBe(['tempo' => 1, 'interval' => 1])
        // A slow projection puts the lone slot on Tempo, so the added day is
        // the stimulus the week does not have.
        ->and($qualityOf(4, 75 * 60.0))->toBe(['tempo' => 1, 'interval' => 1]);
});
