<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Services\Run\Plan\DistanceBandKm;

it('rest is always 0 km regardless of baseline or multiplier', function (): void {
    expect(DistanceBandKm::kmFor(DistanceBand::Rest, 20.0, 1.5))->toBe(0.0);
});

it('long is the baseline scaled by the multiplier', function (): void {
    expect(DistanceBandKm::kmFor(DistanceBand::Long, 20.0, 1.0))->toBe(20.0)
        ->and(DistanceBandKm::kmFor(DistanceBand::Long, 20.0, 0.5))->toBe(10.0);
});

it('medium and short scale proportionally under long', function (): void {
    expect(DistanceBandKm::kmFor(DistanceBand::Medium, 20.0, 1.0))->toBe(13.0)
        ->and(DistanceBandKm::kmFor(DistanceBand::Short, 20.0, 1.0))->toBe(8.0);
});

it('applies the volume multiplier before bucketing into medium/short', function (): void {
    // Taper week at half the baseline: long 10, medium 6.5, short 4.
    expect(DistanceBandKm::kmFor(DistanceBand::Long, 20.0, 0.5))->toBe(10.0)
        ->and(DistanceBandKm::kmFor(DistanceBand::Medium, 20.0, 0.5))->toBe(6.5)
        ->and(DistanceBandKm::kmFor(DistanceBand::Short, 20.0, 0.5))->toBe(4.0);
});
