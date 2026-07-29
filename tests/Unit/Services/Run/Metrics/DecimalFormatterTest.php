<?php

declare(strict_types=1);

use App\Services\Run\Metrics\DecimalFormatter;

it('separates decimals with a comma, never a dot', function (): void {
    expect(DecimalFormatter::decimal(10.47, 2))->toBe('10,47')
        ->and(DecimalFormatter::decimal(24.7))->toBe('24,7')
        ->and(DecimalFormatter::decimal(90.34))->toBe('90,3');
});

it('leaves thousands plain so they cannot read as a decimal', function (): void {
    expect(DecimalFormatter::decimal(1234.5))->toBe('1234,5')
        ->and(DecimalFormatter::decimal(1200.0))->toBe('1200,0');
});

it('pads to the requested precision', function (): void {
    expect(DecimalFormatter::decimal(5.0))->toBe('5,0')
        ->and(DecimalFormatter::decimal(5.0, 2))->toBe('5,00')
        ->and(DecimalFormatter::decimal(0.0))->toBe('0,0');
});

it('drops a trailing decimal zero when trimmed', function (): void {
    expect(DecimalFormatter::trimmed(5.0))->toBe('5')
        ->and(DecimalFormatter::trimmed(35.0, 2))->toBe('35')
        ->and(DecimalFormatter::trimmed(8.2))->toBe('8,2')
        ->and(DecimalFormatter::trimmed(10.47, 2))->toBe('10,47')
        ->and(DecimalFormatter::trimmed(10.40, 2))->toBe('10,4');
});

it('keeps trailing zeros of a whole number when trimmed', function (): void {
    expect(DecimalFormatter::trimmed(100.0))->toBe('100')
        ->and(DecimalFormatter::trimmed(1000.0, 2))->toBe('1000')
        ->and(DecimalFormatter::trimmed(100.0, 0))->toBe('100');
});

it('trims negatives and zero without losing the digit', function (): void {
    expect(DecimalFormatter::trimmed(0.0))->toBe('0')
        ->and(DecimalFormatter::trimmed(-3.50, 2))->toBe('-3,5');
});
