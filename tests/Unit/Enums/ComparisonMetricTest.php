<?php

declare(strict_types=1);

use App\Enums\ComparisonMetric;

it('exposes ef and pace', function (): void {
    expect(array_map(fn (ComparisonMetric $case): string => $case->value, ComparisonMetric::cases()))
        ->toBe(['ef', 'pace']);
});

it('counts an efficiency change from 3% and a pace change from 2%', function (): void {
    expect(ComparisonMetric::Ef->signalPct())->toBe(3.0)
        ->and(ComparisonMetric::Pace->signalPct())->toBe(2.0);
});
