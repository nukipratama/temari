<?php

declare(strict_types=1);

use App\Services\Run\Plan\VolumeRedistributor;

it('returns nothing when there are no eligible days', function (): void {
    expect(VolumeRedistributor::redistribute([], 20.0))->toBe([]);
});

it('returns nothing when every eligible day is already rest (zero km)', function (): void {
    expect(VolumeRedistributor::redistribute(['2026-08-11' => 0.0], 20.0))->toBe([]);
});

it('returns nothing when the original total km is zero, avoiding a divide by zero', function (): void {
    expect(VolumeRedistributor::redistribute(['2026-08-11' => 0.0, '2026-08-13' => 0.0], 20.0))->toBe([]);
});

it('applies the same continuous scale to every eligible day, toward the remaining target', function (): void {
    $eligible = ['2026-08-11' => 5.0, '2026-08-13' => 10.0];
    // original total = 15; target = 20 -> scale 20/15 = 1.333...

    $result = VolumeRedistributor::redistribute($eligible, 20.0);

    expect($result['2026-08-11'])->toBe($result['2026-08-13'])
        ->and($result['2026-08-11'])->toEqualWithDelta(20.0 / 15.0, 0.0001);
});

it('caps how far missed volume may inflate the days that remain', function (): void {
    $eligible = ['2026-08-11' => 5.0, '2026-08-13' => 5.0];
    // original total = 10; an uncapped x4 would apply — the cap holds it at MAX_SCALE.

    $result = VolumeRedistributor::redistribute($eligible, 40.0);

    expect($result['2026-08-11'])->toBe(VolumeRedistributor::MAX_SCALE)
        ->and($result['2026-08-13'])->toBe(VolumeRedistributor::MAX_SCALE)
        ->and(VolumeRedistributor::MAX_SCALE)->toBeLessThan(4.0);
});

it('never scales below the floor when the target is negative', function (): void {
    $result = VolumeRedistributor::redistribute(['2026-08-11' => 15.0], -10.0);

    expect($result['2026-08-11'])->toBe(VolumeRedistributor::MIN_SCALE);
});

/**
 * Reducing is harder than adding: an athlete who banks the week's volume
 * early should still be given a real session, not a token one. The scale-up
 * cap stays where it was, so the two are deliberately asymmetric.
 */
it('never cuts the days that remain below 70% of what they would otherwise ask', function (): void {
    $eligible = ['2026-08-13' => 10.0, '2026-08-16' => 14.0];

    foreach ([0.0, 1.0, 5.0, 12.0] as $overrunRemainder) {
        foreach (VolumeRedistributor::redistribute($eligible, $overrunRemainder) as $scale) {
            expect($scale)->toBeGreaterThanOrEqual(0.7);
        }
    }

    expect(VolumeRedistributor::MIN_SCALE)->toBeLessThan(VolumeRedistributor::MAX_SCALE);
});

it('excludes a rest day (zero km) from the scaled result while scaling the rest', function (): void {
    $eligible = ['2026-08-11' => 0.0, '2026-08-13' => 10.0];

    $result = VolumeRedistributor::redistribute($eligible, 5.0);

    expect($result)->not->toHaveKey('2026-08-11')
        ->and($result['2026-08-13'])->toBe(VolumeRedistributor::MIN_SCALE);
});
