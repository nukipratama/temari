<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Services\Run\Plan\VolumeRedistributor;

const BAND_KM = ['short' => 5.0, 'medium' => 10.0, 'long' => 15.0];

it('returns the input unchanged when there are no eligible days', function (): void {
    expect(VolumeRedistributor::redistribute([], 20.0, BAND_KM))->toBe([]);
});

it('returns the input unchanged when every eligible day is already rest', function (): void {
    $eligible = ['2026-08-11' => DistanceBand::Rest];

    expect(VolumeRedistributor::redistribute($eligible, 20.0, BAND_KM))->toBe($eligible);
});

it('returns the input unchanged when the original total km is zero, avoiding a divide by zero', function (): void {
    $eligible = ['2026-08-11' => DistanceBand::Short];

    expect(VolumeRedistributor::redistribute($eligible, 20.0, ['short' => 0.0, 'medium' => 0.0, 'long' => 0.0]))
        ->toBe($eligible);
});

it('scales training days proportionally toward the remaining target and re-buckets to the nearest band', function (): void {
    $eligible = ['2026-08-11' => DistanceBand::Short, '2026-08-13' => DistanceBand::Medium];
    // original total = 5 + 10 = 15; target = 20 -> scale x1.33: 6.7 stays Short, 13.3 rounds to Long.

    $result = VolumeRedistributor::redistribute($eligible, 20.0, BAND_KM);

    expect($result['2026-08-11'])->toBe(DistanceBand::Short)
        ->and($result['2026-08-13'])->toBe(DistanceBand::Long);
});

it('caps how far missed volume may inflate the days that remain', function (): void {
    $eligible = ['2026-08-11' => DistanceBand::Short, '2026-08-13' => DistanceBand::Short];
    // original total = 10; an uncapped x4 would make both days Long — the cap holds them at 6.75 km.

    $result = VolumeRedistributor::redistribute($eligible, 40.0, BAND_KM);

    expect($result)->toBe($eligible)
        ->and(VolumeRedistributor::MAX_SCALE)->toBeLessThan(4.0);
});

it('never scales below zero when the target is negative', function (): void {
    $eligible = ['2026-08-11' => DistanceBand::Long];

    $result = VolumeRedistributor::redistribute($eligible, -10.0, BAND_KM);

    expect($result['2026-08-11'])->toBe(DistanceBand::Short);
});

it('leaves a rest day among the eligible set untouched while scaling training days', function (): void {
    $eligible = ['2026-08-11' => DistanceBand::Rest, '2026-08-13' => DistanceBand::Medium];

    $result = VolumeRedistributor::redistribute($eligible, 5.0, BAND_KM);

    expect($result['2026-08-11'])->toBe(DistanceBand::Rest)
        ->and($result['2026-08-13'])->toBe(DistanceBand::Short);
});
