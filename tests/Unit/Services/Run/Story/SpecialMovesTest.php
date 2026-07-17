<?php

declare(strict_types=1);

use App\Services\Run\Story\SpecialMoves;

it('returns the default move (Easy Run) when nothing special matched', function (): void {
    expect(new SpecialMoves()->pick([], []))->toBe(SpecialMoves::DEFAULT_MOVE);
});

it('returns Closing Kick on PR + negative split', function (): void {
    $move = new SpecialMoves()->pick(
        ['negative_split' => true],
        ['pr_set' => true, 'distance_m' => 5_000],
    );

    expect($move)->toBe('Closing Kick');
});

it('returns Easy Miles for a long run held sub-Z3', function (): void {
    $move = new SpecialMoves()->pick(
        [
            'time_in_zone_pct' => ['Z1' => 8, 'Z2' => 89, 'Z3' => 3, 'Z4' => 0, 'Z5' => 0],
        ],
        ['distance_m' => 15_000, 'pr_set' => false],
    );

    expect($move)->toBe('Easy Miles');
});

it('returns Tempo Lock when Z3 share exceeds 60 percent', function (): void {
    $move = new SpecialMoves()->pick(
        [
            'time_in_zone_pct' => ['Z2' => 25, 'Z3' => 65, 'Z4' => 10],
        ],
        ['distance_m' => 10_000, 'pr_set' => false],
    );

    expect($move)->toBe('Tempo Lock');
});

it('returns Red Line when the Z4+Z5 hard share is high', function (): void {
    $move = new SpecialMoves()->pick(
        [
            'time_in_zone_pct' => ['Z2' => 20, 'Z3' => 35, 'Z4' => 30, 'Z5' => 15],
        ],
        ['distance_m' => 8_000, 'pr_set' => false],
    );

    expect($move)->toBe('Red Line');
});

it('returns Machine Legs when cadence stays mostly above 175', function (): void {
    $move = new SpecialMoves()->pick(
        [
            'cadence_distribution_pct' => ['<165' => 5, '165-175' => 25, '>175' => 70],
        ],
        ['distance_m' => 5_000, 'pr_set' => false],
    );

    expect($move)->toBe('Machine Legs');
});

it('returns Easy Does It on Z2-dominant runs', function (): void {
    $move = new SpecialMoves()->pick(
        [
            'time_in_zone_pct' => ['Z1' => 10, 'Z2' => 85, 'Z3' => 5],
        ],
        ['distance_m' => 5_000, 'pr_set' => false],
    );

    expect($move)->toBe('Easy Does It');
});

it('returns New Record on a PR without negative split', function (): void {
    $move = new SpecialMoves()->pick(
        ['negative_split' => false, 'time_in_zone_pct' => ['Z3' => 40, 'Z4' => 30]],
        ['pr_set' => true, 'distance_m' => 5_000],
    );

    expect($move)->toBe('New Record');
});

it('returns No Fade on minimal cadence drop at 5k+', function (): void {
    $move = new SpecialMoves()->pick(
        ['cadence_drop_spm' => 0.5, 'time_in_zone_pct' => ['Z2' => 40, 'Z3' => 40]],
        ['distance_m' => 8_000, 'pr_set' => false],
    );

    expect($move)->toBe('No Fade');
});

it('varies the name within a bucket by seed, deterministically', function (): void {
    $context = fn (int $seed): array => ['pr_set' => true, 'distance_m' => 5_000, 'seed' => $seed];
    $summary = ['negative_split' => true];

    // Same bucket (Closing Kick pool), different seeds -> different variants.
    expect(new SpecialMoves()->pick($summary, $context(0)))->toBe('Closing Kick')
        ->and(new SpecialMoves()->pick($summary, $context(1)))->toBe('Late Surge')
        ->and(new SpecialMoves()->pick($summary, $context(2)))->toBe('Final Gear')
        // Wraps around the pool, and the same seed is stable.
        ->and(new SpecialMoves()->pick($summary, $context(3)))->toBe('Closing Kick')
        ->and(new SpecialMoves()->pick($summary, $context(7)))->toBe('Late Surge');
});
