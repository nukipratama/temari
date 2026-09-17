<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Services\Run\Plan\PhaseSchedule;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->schedule = new PhaseSchedule();
});

it('maps race distance to taper weeks at the documented boundaries', function (): void {
    expect($this->schedule->taperWeeksForDistance(10_000))->toBe(1)
        ->and($this->schedule->taperWeeksForDistance(15_000))->toBe(1)
        ->and($this->schedule->taperWeeksForDistance(15_001))->toBe(2)
        ->and($this->schedule->taperWeeksForDistance(21_097))->toBe(2)
        ->and($this->schedule->taperWeeksForDistance(25_000))->toBe(2)
        ->and($this->schedule->taperWeeksForDistance(25_001))->toBe(3)
        ->and($this->schedule->taperWeeksForDistance(42_195))->toBe(3);
});

it('goes taper-only when too little time remains to build anything', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(1); // weeksToRace = 2, taperWeeks(10K) = 1 -> 2 <= 1+1

    $weeks = $this->schedule->forRace($today, $raceDate, 10_000);

    expect($weeks)->toHaveCount(2)
        ->and(array_map(fn (array $w): string => $w['phase']->value, $weeks))->toBe(['taper', 'taper']);
});

it('allocates base/build/peak/taper summing to exactly weeksToRace, in strict order', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(15); // weeksToRace = 16

    $weeks = $this->schedule->forRace($today, $raceDate, 10_000); // taperWeeks = 1

    expect($weeks)->toHaveCount(16);
    $phases = array_map(fn (array $w): string => $w['phase']->value, $weeks);
    expect(array_slice($phases, -1))->toBe(['taper']);

    // Recovery weeks sit inside the Base/Build ramp, so they are skipped when
    // checking that the arc itself never runs backwards.
    $rank = ['base' => 0, 'build' => 1, 'peak' => 2, 'taper' => 3];
    $prev = -1;
    foreach ($phases as $phase) {
        if ($phase === 'deload') {
            continue;
        }
        expect($rank[$phase])->toBeGreaterThanOrEqual($prev);
        $prev = $rank[$phase];
    }
});

it('breaks the base/build ramp with a recovery week every fourth week', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(15); // weeksToRace = 16, taper = 1

    $phases = array_map(
        fn (array $w): string => $w['phase']->value,
        $this->schedule->forRace($today, $raceDate, 10_000),
    );

    // 4 base + 7 build = 11 ramp weeks, so recovery lands on weeks 4 and 8;
    // week 12 is already Peak and keeps its own reduction.
    expect($phases[3])->toBe('deload')
        ->and($phases[7])->toBe('deload')
        ->and(array_slice($phases, -1))->toBe(['taper']);

    // Never inside peak or taper — both are already reductions.
    expect(array_slice($phases, 11))->not->toContain('deload');
});

it('leaves a ramp too short to need one without any recovery week', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(4); // weeksToRace = 5

    $phases = array_map(
        fn (array $w): string => $w['phase']->value,
        $this->schedule->forRace($today, $raceDate, 10_000),
    );

    expect($phases)->not->toContain('deload');
});

it('carries the build ramp across a recovery week instead of restarting it', function (): void {
    $ramp = 1.075;

    $multipliers = PhaseSchedule::volumeMultipliers([
        PlanPhase::Build,
        PlanPhase::Build,
        PlanPhase::Build,
        PlanPhase::Deload,
        PlanPhase::Build,
        PlanPhase::Build,
    ]);

    // Weeks 1-3 climb, week 4 dips off the level reached, and weeks 5-6 pick the
    // ramp back up where it left off rather than restarting at 1.0 — which is
    // what exponentiating within each contiguous run would have done, flattening
    // every split build to a permanent 1.0.
    expect(round($multipliers[2], 4))->toBe(round($ramp ** 2, 4))
        ->and(round($multipliers[3], 4))->toBe(round($ramp ** 2 * 0.65, 4))
        ->and(round($multipliers[4], 4))->toBe(round($ramp ** 3, 4))
        ->and(round($multipliers[5], 4))->toBe(round($ramp ** 4, 4));
});

it('never produces a negative week count when remaining weeks are minimal', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(2); // weeksToRace = 3, taperWeeks = 1, remainingWeeks = 2

    $weeks = $this->schedule->forRace($today, $raceDate, 10_000);

    expect($weeks)->toHaveCount(3);
    $counts = array_count_values(array_map(fn (array $w): string => $w['phase']->value, $weeks));
    expect(array_sum($counts))->toBe(3)
        ->and($counts['taper'] ?? 0)->toBe(1)
        ->and($counts['peak'] ?? 0)->toBe(1);
});

it('week_start values are consecutive Mondays starting at the current week', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(5);

    $weeks = $this->schedule->forRace($today, $raceDate, 10_000);

    foreach ($weeks as $i => $week) {
        expect($week['week_start']->toDateString())->toBe($today->copy()->addWeeks($i)->toDateString());
    }
});

it('self-scaled cycles 3 build weeks then 1 deload week, repeating indefinitely', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);

    $weeks = $this->schedule->selfScaled($today, 8);

    expect(array_map(fn (array $w): string => $w['phase']->value, $weeks))->toBe([
        'build', 'build', 'build', 'deload',
        'build', 'build', 'build', 'deload',
    ]);
});

it('volumeMultipliers keeps Base flat at 1.0', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([PlanPhase::Base, PlanPhase::Base, PlanPhase::Base]);

    expect($multipliers)->toBe([1.0, 1.0, 1.0]);
});

it('volumeMultipliers ramps Build ~7.5% compounding week over week', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([PlanPhase::Build, PlanPhase::Build, PlanPhase::Build]);

    expect($multipliers[0])->toBe(1.0)
        ->and($multipliers[1])->toEqualWithDelta(1.075, 0.0001)
        ->and($multipliers[2])->toEqualWithDelta(1.075 ** 2, 0.0001);
});

it('volumeMultipliers sets Peak slightly below the preceding Build run\'s final multiplier', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([PlanPhase::Build, PlanPhase::Build, PlanPhase::Peak, PlanPhase::Peak]);

    $buildFinal = 1.075 ** 1;
    expect($multipliers[2])->toEqualWithDelta($buildFinal * 0.92, 0.0001)
        ->and($multipliers[3])->toEqualWithDelta($buildFinal * 0.92, 0.0001);
});

it('volumeMultipliers reduces Taper progressively, steepest cut nearest race day', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([
        PlanPhase::Build, PlanPhase::Peak, PlanPhase::Taper, PlanPhase::Taper, PlanPhase::Taper,
    ]);

    $peak = $multipliers[1];
    expect($multipliers[2])->toEqualWithDelta($peak * 0.80, 0.0001)
        ->and($multipliers[3])->toEqualWithDelta($peak * 0.60, 0.0001)
        ->and($multipliers[4])->toEqualWithDelta($peak * 0.40, 0.0001);
});

it('volumeMultipliers reduces Deload off the preceding Build run\'s final multiplier', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([
        PlanPhase::Build, PlanPhase::Build, PlanPhase::Build, PlanPhase::Deload,
    ]);

    $buildFinal = 1.075 ** 2;
    expect($multipliers[3])->toEqualWithDelta($buildFinal * 0.65, 0.0001);
});

/**
 * `diffInWeeks` is signed, so a race day already behind us counted down past
 * zero and `array_fill(0, -1, ...)` threw — killing `plan:regenerate` for
 * every user after the one holding a finished race. `plan:close-finished-races`
 * retires those daily, but four callers reach this method.
 */
it('floors a race already run at a single taper week instead of throwing', function (): void {
    $today = Carbon::parse('2026-09-08');

    foreach ([1, 8, 21, 400] as $daysAgo) {
        $weeks = new PhaseSchedule()->forRace($today, $today->copy()->subDays($daysAgo), 21_097.0);

        expect($weeks)->not->toBeEmpty()
            ->and($weeks[0]['phase'])->toBe(PlanPhase::Taper);
    }
});

it('volumeMultipliers caps the compounding build ramp at 1.4', function (): void {
    $arc = new PhaseSchedule()->forRace(
        Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY),
        Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY)->addWeeks(51),
        10_000.0,
    );

    $multipliers = PhaseSchedule::volumeMultipliers(
        array_map(fn (array $w): PlanPhase => $w['phase'], $arc),
    );

    expect(max($multipliers))->toBeLessThanOrEqual(1.4);
});

it('volumeMultipliers leaves a 12-week block untouched by the ramp cap', function (): void {
    $arc = new PhaseSchedule()->forRace(
        Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY),
        Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY)->addWeeks(11),
        10_000.0,
    );

    $multipliers = PhaseSchedule::volumeMultipliers(
        array_map(fn (array $w): PlanPhase => $w['phase'], $arc),
    );

    expect(max($multipliers))->toEqualWithDelta(1.1556, 0.001);
});

it('holds a self-scaled arc at 1.0 outside its deload dips', function (): void {
    $arc = new PhaseSchedule()->selfScaled(Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY), 30);

    $phases = array_map(fn (array $w): PlanPhase => $w['phase'], $arc);
    $multipliers = PhaseSchedule::volumeMultipliers($phases, selfScaled: true);

    foreach ($phases as $index => $phase) {
        expect($multipliers[$index])->toEqualWithDelta($phase === PlanPhase::Deload ? 0.65 : 1.0, 0.0001);
    }
});

it('opens a sixteen-week block ending on race week up to the half marathon, and a twenty-week one beyond it', function (): void {
    $raceDay = Carbon::parse('2027-03-20');

    expect(PhaseSchedule::blockOpensOn($raceDay, 10_000.0)->toDateString())->toBe('2026-11-30')
        ->and(PhaseSchedule::blockOpensOn($raceDay, 21_097.0)->toDateString())->toBe('2026-11-30')
        ->and(PhaseSchedule::blockOpensOn($raceDay, 25_000.0)->toDateString())->toBe('2026-11-30')
        ->and(PhaseSchedule::blockOpensOn($raceDay, 25_001.0)->toDateString())->toBe('2026-11-02')
        ->and(PhaseSchedule::blockOpensOn($raceDay, 42_195.0)->toDateString())->toBe('2026-11-02');
});

it('keeps a race twelve weeks out as one block, exactly the arc it always was', function (): void {
    $arcStart = Carbon::parse('2026-08-10');

    $arc = $this->schedule->forRace($arcStart, $arcStart->copy()->addWeeks(12), 10_000.0);
    $phases = array_column($arc, 'phase');

    expect(array_map(fn (PlanPhase $p): string => $p->value, $phases))->toBe([
        'base', 'base', 'base', 'deload', 'build', 'build', 'build', 'deload', 'build', 'peak', 'peak', 'peak', 'taper',
    ])
        ->and(array_unique(array_column($arc, 'zone')))->toBe([PhaseSchedule::ZONE_BLOCK])
        ->and(PhaseSchedule::volumeMultipliers($phases, zones: array_column($arc, 'zone')))->toBe(PhaseSchedule::volumeMultipliers($phases));
});

it('runs the general cycle until block open and counts the race ramp from there', function (): void {
    $arcStart = Carbon::parse('2026-08-10');
    $raceDay = $arcStart->copy()->addWeeks(29);
    $blockOpen = PhaseSchedule::blockOpensOn($raceDay, 10_000.0);

    $arc = $this->schedule->forRace($arcStart, $raceDay, 10_000.0);
    $zones = array_column($arc, 'zone');
    $multipliers = PhaseSchedule::volumeMultipliers(array_column($arc, 'phase'), zones: $zones);

    $general = array_slice($arc, 0, 14);
    $block = array_slice($arc, 14);
    $standaloneBlock = $this->schedule->forRace($blockOpen, $raceDay, 10_000.0);

    expect(array_count_values($zones))->toBe([PhaseSchedule::ZONE_GENERAL => 14, PhaseSchedule::ZONE_BLOCK => 16])
        ->and(end($arc)['week_start']->toDateString())->toBe($raceDay->toDateString())
        ->and($block[0]['week_start']->toDateString())->toBe($blockOpen->toDateString())
        ->and(array_column($general, 'phase'))->toBe(array_column($this->schedule->selfScaled($arcStart, 14), 'phase'))
        ->and(array_column($block, 'phase'))->toBe(array_column($standaloneBlock, 'phase'))
        ->and(array_slice($multipliers, 14))->toBe(PhaseSchedule::volumeMultipliers(array_column($standaloneBlock, 'phase')));

    foreach ($general as $i => $week) {
        expect($multipliers[$i])->toEqualWithDelta($week['phase'] === PlanPhase::Deload ? 0.65 : 1.0, 0.0001);
    }
});

it('refuses zones where a general week follows the block', function (): void {
    expect(fn () => PhaseSchedule::volumeMultipliers(
        [PlanPhase::Build, PlanPhase::Build, PlanPhase::Build],
        zones: [PhaseSchedule::ZONE_GENERAL, PhaseSchedule::ZONE_BLOCK, PhaseSchedule::ZONE_GENERAL],
    ))->toThrow(InvalidArgumentException::class);
});

it('marks every self-scaled week as general', function (): void {
    $arc = $this->schedule->selfScaled(Carbon::parse('2026-08-10'), 8);

    expect(array_unique(array_column($arc, 'zone')))->toBe([PhaseSchedule::ZONE_GENERAL]);
});
