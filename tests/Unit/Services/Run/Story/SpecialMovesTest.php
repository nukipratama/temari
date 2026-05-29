<?php

declare(strict_types=1);

use App\Services\Run\Story\SpecialMoves;

it('returns Steady Stride as the default', function (): void {
    expect((new SpecialMoves())->pick([], []))->toBe(SpecialMoves::DEFAULT_MOVE);
});

it('returns Pembalik Keadaan on PR + negative split', function (): void {
    $move = (new SpecialMoves())->pick(
        ['negative_split' => true],
        ['pr_set' => true, 'distance_m' => 5_000],
    );

    expect($move)->toBe('Pembalik Keadaan');
});

it('returns Berdarah Dingin for a long run held sub-Z3', function (): void {
    $move = (new SpecialMoves())->pick(
        [
            'time_in_zone_pct' => ['Z1' => 8, 'Z2' => 89, 'Z3' => 3, 'Z4' => 0, 'Z5' => 0],
        ],
        ['distance_m' => 15_000, 'pr_set' => false],
    );

    expect($move)->toBe('Berdarah Dingin');
});

it('returns Paru-paru Baja when Z3 share exceeds 60 percent', function (): void {
    $move = (new SpecialMoves())->pick(
        [
            'time_in_zone_pct' => ['Z2' => 25, 'Z3' => 65, 'Z4' => 10],
        ],
        ['distance_m' => 10_000, 'pr_set' => false],
    );

    expect($move)->toBe('Paru-paru Baja');
});

it('returns Metronom when cadence stays mostly above 175', function (): void {
    $move = (new SpecialMoves())->pick(
        [
            'cadence_distribution_pct' => ['<165' => 5, '165-175' => 25, '>175' => 70],
        ],
        ['distance_m' => 5_000, 'pr_set' => false],
    );

    expect($move)->toBe('Metronom');
});

it('returns Pemburu Sabar on Z2-dominant runs', function (): void {
    $move = (new SpecialMoves())->pick(
        [
            'time_in_zone_pct' => ['Z1' => 10, 'Z2' => 85, 'Z3' => 5],
        ],
        ['distance_m' => 5_000, 'pr_set' => false],
    );

    expect($move)->toBe('Pemburu Sabar');
});

it('returns Tendangan Awal on a PR without negative split', function (): void {
    $move = (new SpecialMoves())->pick(
        ['negative_split' => false, 'time_in_zone_pct' => ['Z3' => 40, 'Z4' => 30]],
        ['pr_set' => true, 'distance_m' => 5_000],
    );

    expect($move)->toBe('Tendangan Awal');
});

it('returns Tanpa Letih on minimal cadence drop at 5k+', function (): void {
    $move = (new SpecialMoves())->pick(
        ['cadence_drop_spm' => 0.5, 'time_in_zone_pct' => ['Z2' => 40, 'Z3' => 40]],
        ['distance_m' => 8_000, 'pr_set' => false],
    );

    expect($move)->toBe('Tanpa Letih');
});
