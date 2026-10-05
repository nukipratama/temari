<?php

declare(strict_types=1);

use App\Services\Run\Metrics\FallOffExponent;
use Illuminate\Support\Carbon;

function fallOffEffort(string $date, float $meters, float $seconds): array
{
    return ['date' => Carbon::parse($date), 'distance_m' => $meters, 'time_sec' => $seconds];
}

it('fits the slope of log time over log distance within a close cluster', function (): void {
    $k = FallOffExponent::fit([
        fallOffEffort('2026-05-10', 10_000, 3_600),
        fallOffEffort('2026-05-31', 21_097.5, 3_600 * 2.10975 ** 1.1),
    ]);

    expect($k)->toEqualWithDelta(1.1, 0.0001);
});

it('clamps the fitted fall-off to the researched range', function (float $exponent, float $expected): void {
    $k = FallOffExponent::fit([
        fallOffEffort('2026-05-10', 5_000, 1_500),
        fallOffEffort('2026-05-20', 10_000, 1_500 * 2 ** $exponent),
    ]);

    expect($k)->toBe($expected);
})->with([[1.02, FallOffExponent::MIN], [1.25, FallOffExponent::MAX]]);

it('ignores efforts further apart than the cluster window', function (): void {
    expect(FallOffExponent::fit([
        fallOffEffort('2026-05-10', 10_000, 3_600),
        fallOffEffort('2026-08-26', 5_000, 1_600),
    ]))->toBeNull();
});

it('needs the cluster to span enough distance', function (): void {
    expect(FallOffExponent::fit([
        fallOffEffort('2026-05-10', 10_000, 3_600),
        fallOffEffort('2026-05-20', 14_000, 5_200),
    ]))->toBeNull();
});

it('fits the newest valid cluster', function (): void {
    $k = FallOffExponent::fit([
        fallOffEffort('2026-01-10', 5_000, 1_500),
        fallOffEffort('2026-01-20', 10_000, 1_500 * 2 ** 1.14),
        fallOffEffort('2026-05-10', 5_000, 1_400),
        fallOffEffort('2026-05-20', 10_000, 1_400 * 2 ** 1.07),
    ]);

    expect($k)->toEqualWithDelta(1.07, 0.0001);
});

it('falls back to a default for the race distance', function (float $meters, float $expected): void {
    expect(FallOffExponent::forDistance([], $meters))->toBe($expected);
})->with([
    [5_000, FallOffExponent::DEFAULT_UP_TO_10K],
    [10_000, FallOffExponent::DEFAULT_UP_TO_10K],
    [21_097.5, FallOffExponent::DEFAULT_HALF],
    [42_195, FallOffExponent::DEFAULT_MARATHON],
]);
