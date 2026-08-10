<?php

declare(strict_types=1);

use App\Services\Run\Metrics\IntervalDetector;

it('detects alternating work laps with a wide enough pace spread', function (): void {
    // warmup, 3 fast reps alternating with slow recoveries, cooldown.
    $paces = [0 => 360.0, 1 => 240.0, 2 => 360.0, 3 => 240.0, 4 => 360.0, 5 => 240.0, 6 => 360.0];

    expect(IntervalDetector::detect($paces))->toBe([1, 3, 5]);
});

it('returns empty with fewer than 3 laps', function (): void {
    expect(IntervalDetector::detect([0 => 240.0, 1 => 360.0]))->toBe([]);
});

it('returns empty when the pace spread is under the 45 sec/km threshold', function (): void {
    $paces = [0 => 300.0, 1 => 310.0, 2 => 320.0, 3 => 305.0];

    expect(IntervalDetector::detect($paces))->toBe([]);
});

it('returns empty with fewer than 2 work laps', function (): void {
    // Only one lap sits at or below the midpoint.
    $paces = [0 => 600.0, 1 => 240.0, 2 => 610.0, 3 => 605.0];

    expect(IntervalDetector::detect($paces))->toBe([]);
});

it('returns empty when two work laps are adjacent', function (): void {
    $paces = [0 => 240.0, 1 => 245.0, 2 => 600.0];

    expect(IntervalDetector::detect($paces))->toBe([]);
});
