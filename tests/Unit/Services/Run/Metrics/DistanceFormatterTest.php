<?php

declare(strict_types=1);

use App\Services\Run\Metrics\DistanceFormatter;

it('rounds metres to one decimal at the copy precision', function (): void {
    expect(DistanceFormatter::km(10470.0))->toBe(10.5)
        ->and(DistanceFormatter::km(10440.0))->toBe(10.4)
        ->and(DistanceFormatter::km(5000.0))->toBe(5.0);
});

it('rounds metres to two decimals at the exact precision', function (): void {
    expect(DistanceFormatter::km(10470.0, DistanceFormatter::EXACT))->toBe(10.47)
        ->and(DistanceFormatter::km(10475.0, DistanceFormatter::EXACT))->toBe(10.48)
        ->and(DistanceFormatter::km(5000.0, DistanceFormatter::EXACT))->toBe(5.0);
});

it('formats zero metres as zero km at either precision', function (): void {
    expect(DistanceFormatter::km(0.0))->toBe(0.0)
        ->and(DistanceFormatter::km(0.0, DistanceFormatter::EXACT))->toBe(0.0)
        ->and(DistanceFormatter::kmString(0.0))->toBe('0.0')
        ->and(DistanceFormatter::kmString(0.0, DistanceFormatter::EXACT))->toBe('0.00');
});

it('passes null through kmOrNull and kmString', function (): void {
    expect(DistanceFormatter::kmOrNull(null))->toBeNull()
        ->and(DistanceFormatter::kmOrNull(null, DistanceFormatter::EXACT))->toBeNull()
        ->and(DistanceFormatter::kmString(null))->toBeNull();
});

it('rounds a present distance through kmOrNull', function (): void {
    expect(DistanceFormatter::kmOrNull(10470.0))->toBe(10.5)
        ->and(DistanceFormatter::kmOrNull(10470.0, DistanceFormatter::EXACT))->toBe(10.47);
});

it('renders a fixed-decimal string at each precision', function (): void {
    expect(DistanceFormatter::kmString(10470.0))->toBe('10.5')
        ->and(DistanceFormatter::kmString(10470.0, DistanceFormatter::EXACT))->toBe('10.47')
        ->and(DistanceFormatter::kmString(5000.0))->toBe('5.0');
});

it('groups thousands in kmString, matching number_format', function (): void {
    expect(DistanceFormatter::kmString(1234500.0))->toBe('1,234.5');
});
