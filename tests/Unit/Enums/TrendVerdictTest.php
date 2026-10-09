<?php

declare(strict_types=1);

use App\Enums\TrendVerdict;

it('exposes the five outcomes the home screen can render', function (): void {
    expect(array_map(fn (TrendVerdict $case): string => $case->value, TrendVerdict::cases()))
        ->toBe(['improving', 'plateaued', 'slipped', 'mixed', 'not_enough_history']);
});
