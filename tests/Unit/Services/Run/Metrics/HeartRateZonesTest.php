<?php

declare(strict_types=1);

use App\Services\Run\Metrics\HeartRateZones;

it('reproduces the configured defaults at max 180 / rest 55', function (): void {
    expect(HeartRateZones::derive(180, 55))->toBe(config('runner.hr_zones'));
});

it('makes the bands gapless so a boundary bpm lands in exactly one zone', function (): void {
    $zones = HeartRateZones::derive(190, 50);

    expect($zones['Z1']['hi'])->toBe($zones['Z2']['lo'])
        ->and($zones['Z2']['hi'])->toBe($zones['Z3']['lo'])
        ->and($zones['Z3']['hi'])->toBe($zones['Z4']['lo'])
        ->and($zones['Z4']['hi'])->toBe($zones['Z5']['lo']);
});

it('leaves Z5 open-ended', function (): void {
    expect(HeartRateZones::derive(190, 50)['Z5']['hi'])->toBe(999);
});

it('shifts every band up when max HR rises', function (): void {
    $before = HeartRateZones::derive(180, 55);
    $after = HeartRateZones::derive(188, 55);

    // The whole point of reconciling a stale max: an easy run that read as Z3
    // against the old ceiling reads as Z2 against the corrected one.
    expect($after['Z3']['lo'])->toBeGreaterThan($before['Z3']['lo'])
        ->and($after['Z2']['lo'])->toBeGreaterThan($before['Z2']['lo']);
});

it('accepts a plausible max HR and rejects interference', function (int $bpm, bool $plausible): void {
    expect(HeartRateZones::isPlausibleMax($bpm))->toBe($plausible);
})->with([
    'strap interference' => [245, false],
    'at the ceiling' => [220, true],
    'ordinary athlete' => [188, true],
    'at the floor' => [120, true],
    'implausibly low' => [119, false],
]);
