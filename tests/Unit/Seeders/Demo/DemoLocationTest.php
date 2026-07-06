<?php

declare(strict_types=1);

use Database\Seeders\Demo\DemoLocation;

it('defaults the country to Indonesia', function (): void {
    $location = new DemoLocation(-6.2, 106.8, 'Somewhere, Jakarta, Indonesia');

    expect($location->country)->toBe('ID');
});

it('exposes a curated list of Indonesian running spots', function (): void {
    $library = DemoLocation::library();

    expect($library)->not->toBeEmpty();

    foreach ($library as $location) {
        expect($location)->toBeInstanceOf(DemoLocation::class)
            ->and($location->country)->toBe('ID')
            ->and($location->name)->toContain('Indonesia')
            ->and($location->name)->not->toBe('')
            // Indonesia straddles the equator but every seeded spot sits south of it.
            ->and($location->lat)->toBeLessThan(0.0)
            // Indonesian longitudes run ~95°E–141°E.
            ->and($location->lng)->toBeGreaterThan(95.0)
            ->and($location->lng)->toBeLessThan(141.0);
    }
});

it('spans more than one distinct location so maps vary', function (): void {
    $names = array_map(fn (DemoLocation $l): string => $l->name, DemoLocation::library());

    expect(count(array_unique($names)))->toBeGreaterThan(1);
});

it('default returns the first library entry (Gelora Bung Karno)', function (): void {
    expect(DemoLocation::default())->toEqual(DemoLocation::library()[0]);
});
