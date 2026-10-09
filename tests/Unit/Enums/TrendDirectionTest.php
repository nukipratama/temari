<?php

declare(strict_types=1);

use App\Enums\TrendDirection;

it('exposes better, flat and worse', function (): void {
    expect(array_map(fn (TrendDirection $case): string => $case->value, TrendDirection::cases()))
        ->toBe(['better', 'flat', 'worse']);
});

it('answers its own identity checks', function (): void {
    expect(TrendDirection::Better->isBetter())->toBeTrue()
        ->and(TrendDirection::Better->isWorse())->toBeFalse()
        ->and(TrendDirection::Worse->isBetter())->toBeFalse()
        ->and(TrendDirection::Worse->isWorse())->toBeTrue();
});
