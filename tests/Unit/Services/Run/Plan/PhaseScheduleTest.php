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
