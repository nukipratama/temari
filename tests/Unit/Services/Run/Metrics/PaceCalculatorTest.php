<?php

declare(strict_types=1);

use App\Services\Run\Metrics\PaceCalculator;

it('computes pace in seconds per km', function (): void {
    // 5 km in 25:00 (1500s) → 300 s/km.
    expect(PaceCalculator::secPerKm(5000.0, 1500))->toBe(300.0);
});

it('handles fractional distances', function (): void {
    // 5001 m in 300s → ~59.988 s/km.
    expect(PaceCalculator::secPerKm(5001.0, 300))->toEqualWithDelta(59.988, 0.001);
});

it('returns null when distance or time is missing or non-positive', function (?float $distance, int|float|null $time): void {
    expect(PaceCalculator::secPerKm($distance, $time))->toBeNull();
})->with([
    'null distance' => [null, 1500],
    'null time' => [5000.0, null],
    'zero distance' => [0.0, 1500],
    'zero time' => [5000.0, 0],
    'negative distance' => [-100.0, 1500],
    'negative time' => [5000.0, -10],
]);
