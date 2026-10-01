<?php

declare(strict_types=1);

use App\Services\Run\Metrics\LoadBalance;
use App\Services\Run\Metrics\TrainingFormStatus;

it('has exactly the three presented states', function (): void {
    expect(array_map(static fn (LoadBalance $b): string => $b->value, LoadBalance::cases()))
        ->toBe(['fresh', 'steady', 'heavy']);
});

it('maps the four stored form statuses onto the three presented states', function (): void {
    expect(TrainingFormStatus::Fresh->loadBalance())->toBe(LoadBalance::Fresh)
        ->and(TrainingFormStatus::Optimal->loadBalance())->toBe(LoadBalance::Steady)
        ->and(TrainingFormStatus::Fatigued->loadBalance())->toBe(LoadBalance::Heavy)
        ->and(TrainingFormStatus::Overreaching->loadBalance())->toBe(LoadBalance::Heavy);
});

it('reads a stored form status string, null for none or unknown', function (): void {
    expect(LoadBalance::fromStored('optimal'))->toBe(LoadBalance::Steady)
        ->and(LoadBalance::fromStored('overreaching'))->toBe(LoadBalance::Heavy)
        ->and(LoadBalance::fromStored(null))->toBeNull()
        ->and(LoadBalance::fromStored('nonsense'))->toBeNull();
});
