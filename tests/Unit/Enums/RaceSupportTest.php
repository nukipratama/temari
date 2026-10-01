<?php

declare(strict_types=1);

use App\Enums\RaceSupport;

it('supports dedicated road preparation through the marathon', function (float $distanceM): void {
    expect(RaceSupport::forDistance($distanceM))->toBe(RaceSupport::Road)
        ->and(RaceSupport::forDistance($distanceM)->dedicatedPreparation())->toBeTrue();
})->with([5_000.0, 21_097.0, 42_195.0, 42_200.0]);

it('treats anything beyond the marathon as general aerobic maintenance', function (float $distanceM): void {
    expect(RaceSupport::forDistance($distanceM))->toBe(RaceSupport::GeneralMaintenance)
        ->and(RaceSupport::forDistance($distanceM)->dedicatedPreparation())->toBeFalse();
})->with([42_400.0, 50_000.0, 100_000.0, 300_000.0]);

it('gives marathon-specific work only to marathon-class road distances', function (): void {
    expect(RaceSupport::isMarathonClass(null))->toBeFalse()
        ->and(RaceSupport::isMarathonClass(21_097.0))->toBeFalse()
        ->and(RaceSupport::isMarathonClass(25_000.0))->toBeFalse()
        ->and(RaceSupport::isMarathonClass(25_001.0))->toBeTrue()
        ->and(RaceSupport::isMarathonClass(42_195.0))->toBeTrue()
        ->and(RaceSupport::isMarathonClass(50_000.0))->toBeFalse();
});

it('states the limit of ultra support only for general maintenance', function (): void {
    expect(RaceSupport::Road->limitation())->toBeNull()
        ->and(RaceSupport::GeneralMaintenance->limitation())->toContain('general aerobic maintenance')
        ->and(RaceSupport::GeneralMaintenance->limitation())->toContain('ultra');
});
