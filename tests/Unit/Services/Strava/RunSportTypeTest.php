<?php

declare(strict_types=1);

use App\Services\Strava\RunSportType;

it('matches the three run sport types via sport_type', function (string $type): void {
    expect(RunSportType::matches(['sport_type' => $type]))->toBeTrue();
})->with(['Run', 'VirtualRun', 'TrailRun']);

it('falls back to the legacy type field when sport_type is absent', function (): void {
    expect(RunSportType::matches(['type' => 'Run']))->toBeTrue();
});

it('prefers sport_type over the legacy type field', function (): void {
    // A ride wrongly tagged Run under the legacy field must still read as a ride.
    expect(RunSportType::matches(['sport_type' => 'Ride', 'type' => 'Run']))->toBeFalse();
});

it('does not match non-run sport types', function (string $type): void {
    expect(RunSportType::matches(['sport_type' => $type]))->toBeFalse();
})->with(['Ride', 'Walk', 'Swim', 'Workout', 'Hike']);

it('does not match when no sport type is present', function (): void {
    expect(RunSportType::matches([]))->toBeFalse();
});

it('flags an explicitly non-run activity as non-run', function (string $type): void {
    expect(RunSportType::isExplicitlyNotRun(['sport_type' => $type]))->toBeTrue();
})->with(['Ride', 'Walk', 'Swim']);

it('does NOT flag a run as explicitly non-run', function (string $type): void {
    expect(RunSportType::isExplicitlyNotRun(['sport_type' => $type]))->toBeFalse();
})->with(['Run', 'VirtualRun', 'TrailRun']);

it('does NOT flag a payload with no sport type as explicitly non-run', function (): void {
    // Fail-open: without a positively-identified type we never delete.
    expect(RunSportType::isExplicitlyNotRun([]))->toBeFalse()
        ->and(RunSportType::isExplicitlyNotRun(['sport_type' => '']))->toBeFalse();
});
