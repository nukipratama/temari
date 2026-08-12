<?php

declare(strict_types=1);

use App\Enums\TrendDirection;

it('exposes better, flat and worse', function (): void {
    expect(array_map(fn (TrendDirection $case): string => $case->value, TrendDirection::cases()))
        ->toBe(['better', 'flat', 'worse']);
});

it('answers its own identity checks', function (): void {
    expect(TrendDirection::Better->isBetter())->toBeTrue()
        ->and(TrendDirection::Better->isFlat())->toBeFalse()
        ->and(TrendDirection::Better->isWorse())->toBeFalse()
        ->and(TrendDirection::Flat->isFlat())->toBeTrue()
        ->and(TrendDirection::Flat->isBetter())->toBeFalse()
        ->and(TrendDirection::Worse->isWorse())->toBeTrue()
        ->and(TrendDirection::Worse->isFlat())->toBeFalse();
});
