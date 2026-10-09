<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Services\Run\Plan\PhaseSchedule;
use Illuminate\Support\Carbon;

it('maps race distance to taper weeks at the documented boundaries', function (): void {
    expect(PhaseSchedule::taperWeeksForDistance(5_000))->toBe(2)
        ->and(PhaseSchedule::taperWeeksForDistance(10_000))->toBe(2)
        ->and(PhaseSchedule::taperWeeksForDistance(15_000))->toBe(2)
        ->and(PhaseSchedule::taperWeeksForDistance(15_001))->toBe(2)
        ->and(PhaseSchedule::taperWeeksForDistance(21_097))->toBe(2)
        ->and(PhaseSchedule::taperWeeksForDistance(25_000))->toBe(2)
        ->and(PhaseSchedule::taperWeeksForDistance(25_001))->toBe(3)
        ->and(PhaseSchedule::taperWeeksForDistance(42_195))->toBe(3);
});

it('goes taper-only when too little time remains to build anything', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(1); // weeksToRace = 2, taperWeeks(10K) = 2 -> 2 <= 2+1

    $weeks = PhaseSchedule::forRace($today, $raceDate, 10_000);

    expect($weeks)->toHaveCount(2)
        ->and(array_map(fn (array $w): string => $w['phase']->value, $weeks))->toBe(['taper', 'taper']);
});

it('allocates base/build/peak/taper summing to exactly weeksToRace, in strict order', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(15); // weeksToRace = 16

    $weeks = PhaseSchedule::forRace($today, $raceDate, 10_000); // taperWeeks = 2

    expect($weeks)->toHaveCount(16);
    $phases = array_map(fn (array $w): string => $w['phase']->value, $weeks);
    expect(array_slice($phases, -2))->toBe(['taper', 'taper']);

    // Recovery weeks sit inside the Base/Build/Peak run, so they are skipped when
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

it('breaks the base, build and peak run with a recovery week every fourth week', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(15); // weeksToRace = 16, taper = 2

    $phases = array_map(
        fn (array $w): string => $w['phase']->value,
        PhaseSchedule::forRace($today, $raceDate, 10_000),
    );

    // 4 base + 6 build + 4 peak = 14 weeks before the taper, so recovery lands on weeks 4, 8 and 12.
    expect($phases)->toBe([
        'base', 'base', 'base', 'deload', 'build', 'build', 'build', 'deload',
        'build', 'build', 'peak', 'deload', 'peak', 'peak', 'taper', 'taper',
    ]);
});

it('leaves a ramp too short to need one without any recovery week', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(4); // weeksToRace = 5

    $phases = array_map(
        fn (array $w): string => $w['phase']->value,
        PhaseSchedule::forRace($today, $raceDate, 10_000),
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
    $raceDate = $today->copy()->addWeeks(3); // weeksToRace = 4, taperWeeks = 2, remainingWeeks = 2

    $weeks = PhaseSchedule::forRace($today, $raceDate, 10_000);

    expect($weeks)->toHaveCount(4);
    $counts = array_count_values(array_map(fn (array $w): string => $w['phase']->value, $weeks));
    expect(array_sum($counts))->toBe(4)
        ->and($counts['taper'] ?? 0)->toBe(2)
        ->and($counts['peak'] ?? 0)->toBe(1)
        ->and($counts['build'] ?? 0)->toBe(1);
});

it('week_start values are consecutive Mondays starting at the current week', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $today->copy()->addWeeks(5);

    $weeks = PhaseSchedule::forRace($today, $raceDate, 10_000);

    foreach ($weeks as $i => $week) {
        expect($week['week_start']->toDateString())->toBe($today->copy()->addWeeks($i)->toDateString());
    }
});

it('self-scaled cycles 3 build weeks then 1 deload week, repeating indefinitely', function (): void {
    $today = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);

    $weeks = PhaseSchedule::selfScaled($today, 8);

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

it('volumeMultipliers holds Peak at the preceding Build run\'s final multiplier', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([PlanPhase::Build, PlanPhase::Build, PlanPhase::Peak, PlanPhase::Peak]);

    expect($multipliers[2])->toEqualWithDelta(1.075, 0.0001)
        ->and($multipliers[3])->toEqualWithDelta(1.075, 0.0001);
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
        $weeks = PhaseSchedule::forRace($today, $today->copy()->subDays($daysAgo), 21_097.0);

        expect($weeks)->not->toBeEmpty()
            ->and($weeks[0]['phase'])->toBe(PlanPhase::Taper);
    }
});

it('volumeMultipliers caps the compounding build ramp at 1.4', function (): void {
    $arc = PhaseSchedule::forRace(
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
    $arc = PhaseSchedule::forRace(
        Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY),
        Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY)->addWeeks(11),
        10_000.0,
    );

    $multipliers = PhaseSchedule::volumeMultipliers(
        array_map(fn (array $w): PlanPhase => $w['phase'], $arc),
    );

    expect(max($multipliers))->toEqualWithDelta(1.075 ** 3, 0.001);
});

it('holds a self-scaled arc at 1.0 outside its deload dips', function (): void {
    $arc = PhaseSchedule::selfScaled(Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY), 30);

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

    $arc = PhaseSchedule::forRace($arcStart, $arcStart->copy()->addWeeks(12), 10_000.0);
    $phases = array_column($arc, 'phase');

    expect(array_map(fn (PlanPhase $p): string => $p->value, $phases))->toBe([
        'base', 'base', 'base', 'deload', 'build', 'build', 'build', 'deload', 'peak', 'peak', 'peak', 'taper', 'taper',
    ])
        ->and(array_unique(array_column($arc, 'zone')))->toBe([PhaseSchedule::ZONE_BLOCK])
        ->and(PhaseSchedule::volumeMultipliers($phases, zones: array_column($arc, 'zone')))->toBe(PhaseSchedule::volumeMultipliers($phases));
});

it('runs the general cycle until block open and counts the race ramp from there', function (): void {
    $arcStart = Carbon::parse('2026-08-10');
    $raceDay = $arcStart->copy()->addWeeks(27);
    $blockOpen = PhaseSchedule::blockOpensOn($raceDay, 10_000.0);

    $arc = PhaseSchedule::forRace($arcStart, $raceDay, 10_000.0);
    $zones = array_column($arc, 'zone');
    $multipliers = PhaseSchedule::volumeMultipliers(array_column($arc, 'phase'), zones: $zones);

    $general = array_slice($arc, 0, 12);
    $block = array_slice($arc, 12);
    $standaloneBlock = PhaseSchedule::forRace($blockOpen, $raceDay, 10_000.0);

    expect(array_count_values($zones))->toBe([PhaseSchedule::ZONE_GENERAL => 12, PhaseSchedule::ZONE_BLOCK => 16])
        ->and(end($arc)['week_start']->toDateString())->toBe($raceDay->toDateString())
        ->and($block[0]['week_start']->toDateString())->toBe($blockOpen->toDateString())
        ->and(array_column($general, 'phase'))->toBe(array_column(PhaseSchedule::selfScaled($arcStart, 12), 'phase'))
        ->and(array_column($block, 'phase'))->toBe(array_column($standaloneBlock, 'phase'))
        ->and(array_slice($multipliers, 12))->toBe(PhaseSchedule::volumeMultipliers(array_column($standaloneBlock, 'phase')));

    foreach ($general as $i => $week) {
        expect($multipliers[$i])->toEqualWithDelta($week['phase'] === PlanPhase::Deload ? 0.65 : 1.0, 0.0001);
    }
});

it('ends a general cycle two or more weeks past its last recovery week on one before the block', function (int $generalWeeks, array $seam): void {
    $arcStart = Carbon::parse('2026-08-10');
    $raceDay = $arcStart->copy()->addWeeks($generalWeeks + 15);

    $arc = PhaseSchedule::forRace($arcStart, $raceDay, 10_000.0);
    $phases = array_map(fn (PlanPhase $phase): string => $phase->value, array_column($arc, 'phase'));
    $standaloneBlock = PhaseSchedule::forRace(PhaseSchedule::blockOpensOn($raceDay, 10_000.0), $raceDay, 10_000.0);

    expect(array_slice($phases, $generalWeeks - 3, 7))->toBe($seam)
        ->and(array_column(array_slice($arc, $generalWeeks), 'phase'))->toBe(array_column($standaloneBlock, 'phase'));
})->with([
    'one week past' => [13, ['build', 'deload', 'build', 'base', 'base', 'base', 'deload']],
    'two weeks past' => [14, ['deload', 'build', 'deload', 'base', 'base', 'base', 'deload']],
    'three weeks past' => [15, ['build', 'build', 'deload', 'base', 'base', 'base', 'deload']],
]);

it('refuses zones where a general week follows the block', function (): void {
    expect(fn () => PhaseSchedule::volumeMultipliers(
        [PlanPhase::Build, PlanPhase::Build, PlanPhase::Build],
        zones: [PhaseSchedule::ZONE_GENERAL, PhaseSchedule::ZONE_BLOCK, PhaseSchedule::ZONE_GENERAL],
    ))->toThrow(InvalidArgumentException::class);
});

it('marks every self-scaled week as general', function (): void {
    $arc = PhaseSchedule::selfScaled(Carbon::parse('2026-08-10'), 8);

    expect(array_unique(array_column($arc, 'zone')))->toBe([PhaseSchedule::ZONE_GENERAL]);
});

it('moves a recovery week off the last week before the taper so a Peak week leads into it', function (): void {
    $arcStart = Carbon::parse('2026-09-14');

    $phases = array_column(PhaseSchedule::forRace($arcStart, Carbon::parse('2026-11-22'), 10_000.0), 'phase');

    expect(array_map(fn (PlanPhase $p): string => $p->value, $phases))->toBe([
        'base', 'base', 'build', 'deload', 'build', 'build', 'deload', 'peak', 'taper', 'taper',
    ]);
});

it('takes a recovery week inside Peak off the build level and returns to it after', function (): void {
    $multipliers = PhaseSchedule::volumeMultipliers([
        PlanPhase::Build, PlanPhase::Build, PlanPhase::Peak, PlanPhase::Deload, PlanPhase::Peak, PlanPhase::Taper, PlanPhase::Taper,
    ]);

    expect($multipliers[2])->toEqualWithDelta(1.075, 0.0001)
        ->and($multipliers[3])->toEqualWithDelta(1.075 * 0.65, 0.0001)
        ->and($multipliers[4])->toEqualWithDelta(1.075, 0.0001)
        ->and($multipliers[5])->toEqualWithDelta(1.075 * 0.60, 0.0001)
        ->and($multipliers[6])->toEqualWithDelta(1.075 * 0.40, 0.0001);
});

it('never lets a race block reach Taper straight out of a recovery week', function (int $weeksOut, float $distanceM): void {
    $arcStart = Carbon::parse('2026-08-10');

    $phases = array_column(PhaseSchedule::forRace($arcStart, $arcStart->copy()->addWeeks($weeksOut), $distanceM), 'phase');

    foreach ($phases as $i => $phase) {
        if ($phase === PlanPhase::Deload) {
            expect($phases[$i + 1])->not->toBe(PlanPhase::Taper);
        }
    }
})->with(function (): array {
    $arcStart = Carbon::parse('2026-08-10');

    $cases = [];
    foreach ([21_097.0, 42_195.0] as $distanceM) {
        foreach (range(4, 20) as $weeksOut) {
            $phases = array_column(PhaseSchedule::forRace($arcStart, $arcStart->copy()->addWeeks($weeksOut), $distanceM), 'phase');
            // A block this short holds no scheduled recovery week at all
            // (already proven by "leaves a ramp too short to need one without
            // any recovery week"), so there is nothing here for this
            // invariant to check.
            if (! in_array(PlanPhase::Deload, $phases, true)) {
                continue;
            }
            $cases["{$distanceM} m, {$weeksOut} weeks"] = [$weeksOut, $distanceM];
        }
    }

    return $cases;
});

dataset('race blocks', function (): array {
    $cases = [];
    foreach ([21_097.0, 42_195.0] as $distanceM) {
        foreach ([8, 10, 12, 16, 20, 30] as $weeks) {
            $cases["{$distanceM} m, {$weeks} weeks"] = [$weeks, $distanceM];
        }
    }

    return $cases;
});

it('climbs or holds the block\'s volume outside its recovery weeks until the taper', function (int $weeks, float $distanceM): void {
    $arcStart = Carbon::parse('2026-08-10');
    $arc = PhaseSchedule::forRace($arcStart, $arcStart->copy()->addWeeks($weeks - 1), $distanceM);
    $phases = array_column($arc, 'phase');
    $multipliers = PhaseSchedule::volumeMultipliers($phases, zones: array_column($arc, 'zone'));

    $highWater = 0.0;
    foreach ($phases as $i => $phase) {
        if ($phase === PlanPhase::Taper) {
            break;
        }
        if ($phase === PlanPhase::Deload || $arc[$i]['zone'] === PhaseSchedule::ZONE_GENERAL) {
            continue;
        }
        expect($multipliers[$i])->toBeGreaterThanOrEqual($highWater);
        $highWater = $multipliers[$i];
    }
})->with('race blocks');

it('never runs a season arc more than four weeks without a recovery or taper week', function (int $weeks, float $distanceM): void {
    $arcStart = Carbon::parse('2026-08-10');
    $arc = PhaseSchedule::forRace($arcStart, $arcStart->copy()->addWeeks($weeks - 1), $distanceM);

    $run = 0;
    foreach ($arc as $week) {
        $run = in_array($week['phase'], [PlanPhase::Deload, PlanPhase::Taper], true) ? 0 : $run + 1;
        expect($run)->toBeLessThanOrEqual(4);
    }
})->with('race blocks');

it('tapers a race up to 25 km for two weeks at 0.6 and 0.4 of the build level', function (int $weeks, float $distanceM): void {
    $arcStart = Carbon::parse('2026-08-10');
    $arc = PhaseSchedule::forRace($arcStart, $arcStart->copy()->addWeeks($weeks - 1), $distanceM);
    $phases = array_column($arc, 'phase');
    $multipliers = PhaseSchedule::volumeMultipliers($phases, zones: array_column($arc, 'zone'));

    $buildWeeks = count(array_filter(
        $arc,
        fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK && $week['phase'] === PlanPhase::Build,
    ));
    $buildLevel = min(1.4, 1.075 ** ($buildWeeks - 1));

    expect(array_slice($phases, -3))->toBe([PlanPhase::Peak, PlanPhase::Taper, PlanPhase::Taper])
        ->and(end($multipliers))->toEqualWithDelta($buildLevel * 0.4, 0.0001)
        ->and(prev($multipliers))->toEqualWithDelta($buildLevel * 0.6, 0.0001);
})->with(function (): array {
    $cases = [];
    foreach ([21_097.0] as $distanceM) {
        foreach ([8, 10, 12, 16, 20, 30] as $weeks) {
            $cases["{$distanceM} m, {$weeks} weeks"] = [$weeks, $distanceM];
        }
    }

    return $cases;
});

it('keeps a race beyond the marathon in the general zone with one taper week', function (): void {
    $start = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
    $raceDate = $start->copy()->addWeeks(9)->addDays(5);

    $weeks = PhaseSchedule::forRace($start, $raceDate, 80_000);

    expect($weeks)->toHaveCount(10)
        ->and(array_unique(array_column($weeks, 'zone')))->toBe([PhaseSchedule::ZONE_GENERAL])
        ->and($weeks[9]['phase'])->toBe(PlanPhase::Taper)
        ->and($weeks[8]['phase'])->not->toBe(PlanPhase::Taper)
        ->and($weeks[9]['week_start']->toDateString())->toBe($raceDate->copy()->startOfWeek(Carbon::MONDAY)->toDateString());
});

it('treats the marathon distance as dedicated road preparation with its own block', function (): void {
    $start = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);

    $weeks = PhaseSchedule::forRace($start, $start->copy()->addWeeks(19), 42_195);

    expect(array_column($weeks, 'zone'))->toContain(PhaseSchedule::ZONE_BLOCK)
        ->and($weeks[19]['phase'])->toBe(PlanPhase::Taper);
});
