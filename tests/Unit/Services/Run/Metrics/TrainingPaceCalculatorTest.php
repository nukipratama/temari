<?php

declare(strict_types=1);

use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;

function paceCalculator(): TrainingPaceCalculator
{
    return app(TrainingPaceCalculator::class);
}

function racePaceFor(float $vdot, float $meters): float
{
    return app(VdotEstimator::class)->raceTimeForVdot($vdot, $meters) / ($meters / 1000);
}

it('puts marathon at its race equivalent and threshold faster than half-marathon pace on the athlete\'s own VDOT model', function (float $vdot): void {
    $paces = paceCalculator()->fromVdot($vdot);

    expect($paces)->toHaveKeys(['easy', 'marathon', 'threshold', 'interval'])
        ->and((float) $paces['marathon'])->toEqualWithDelta(racePaceFor($vdot, 42_195.0), 2.0)
        ->and((float) $paces['threshold'])->toBeLessThan(racePaceFor($vdot, 21_097.5))
        ->and($paces['easy'])->toBeGreaterThan($paces['marathon']);
})->with([30.0, 40.0, 50.0, 60.0]);

it('sets threshold at the pace the athlete could race for an hour', function (float $vdot): void {
    $threshold = paceCalculator()->fromVdot($vdot)['threshold'];

    expect(app(VdotEstimator::class)->raceTimeForVdot($vdot, 3_600 / $threshold * 1000))->toEqualWithDelta(3_600.0, 15.0);
})->with([30.0, 40.0, 50.0, 60.0]);

it('sets interval at the pace the athlete could race for about eleven minutes', function (float $vdot): void {
    $interval = paceCalculator()->fromVdot($vdot)['interval'];

    expect(app(VdotEstimator::class)->raceTimeForVdot($vdot, 660 / $interval * 1000))->toEqualWithDelta(660.0, 5.0);
})->with([30.0, 40.0, 50.0, 60.0]);

it('keeps threshold slower than 10K pace once the athlete runs 10K inside the hour', function (float $vdot): void {
    expect((float) paceCalculator()->fromVdot($vdot)['threshold'])->toBeGreaterThan(racePaceFor($vdot, 10_000.0));
})->with([40.0, 50.0, 60.0]);

it('keeps interval between 3K and 5K pace once the athlete runs 3K inside eleven minutes', function (): void {
    $interval = (float) paceCalculator()->fromVdot(60.0)['interval'];

    expect($interval)->toBeGreaterThan(racePaceFor(60.0, 3_000.0))
        ->and($interval)->toBeLessThan(racePaceFor(60.0, 5_000.0));
});

it('guides easy at the midpoint of the VDOT calculator\'s easy band', function (float $vdot, int $fastEnd, int $slowEnd): void {
    $easy = paceCalculator()->fromVdot($vdot)['easy'];
    $slowest = paceCalculator()->easySlowEndSecPerKm($vdot);

    expect((float) $easy)->toEqualWithDelta(($fastEnd + $slowEnd) / 2, 5.0)
        ->and((float) $slowest)->toEqualWithDelta($slowEnd, 5.0);
})->with([
    'VDOT 30' => [30.0, 425, 466],
    'VDOT 35' => [35.0, 395, 434],
    'VDOT 39.9' => [39.9, 367, 403],
    'VDOT 50' => [50.0, 307, 339],
    'VDOT 60' => [60.0, 265, 293],
]);

it('orders paces from fastest (interval) to slowest (easy)', function (): void {
    $paces = paceCalculator()->fromVdot(29.0);

    expect($paces['interval'])->toBeLessThan($paces['threshold'])
        ->and($paces['threshold'])->toBeLessThan($paces['marathon'])
        ->and($paces['marathon'])->toBeLessThan($paces['easy']);
});

it('produces faster easy pace for a higher VDOT (monotonic)', function (): void {
    $lowerVdot = paceCalculator()->fromVdot(29.0);
    $higherVdot = paceCalculator()->fromVdot(45.0);

    expect($higherVdot['easy'])->toBeLessThan($lowerVdot['easy'])
        ->and($higherVdot['threshold'])->toBeLessThan($lowerVdot['threshold'])
        ->and($higherVdot['interval'])->toBeLessThan($lowerVdot['interval'])
        ->and($higherVdot['marathon'])->toBeLessThan($lowerVdot['marathon']);
});

it('reads the quality anchor for threshold and interval while easy and marathon stay on the endurance one', function (): void {
    $single = paceCalculator()->fromVdot(28.4);
    $split = paceCalculator()->fromVdot(28.4, 31.9);

    expect($split['easy'])->toBe($single['easy'])
        ->and($split['marathon'])->toBe($single['marathon'])
        ->and($split['threshold'])->toBeLessThan($single['threshold'])
        ->and($split['interval'])->toBeLessThan($single['interval']);
});

it('falls back to the single anchor when no quality anchor is supplied', function (): void {
    expect(paceCalculator()->fromVdotResult(['vdot' => 30.0]))
        ->toBe(paceCalculator()->fromVdot(30.0));
});

it('exposes the easy band\'s slow end without moving the averaged easy figure', function (): void {
    $paces = paceCalculator()->fromVdot(29.0);

    expect(paceCalculator()->easySlowEndSecPerKm(29.0))
        ->toBeInt()
        ->toBeGreaterThan($paces['easy']);
});

it('produces a faster slow-end pace for a higher VDOT (monotonic)', function (): void {
    expect(paceCalculator()->easySlowEndSecPerKm(45.0))
        ->toBeLessThan(paceCalculator()->easySlowEndSecPerKm(29.0));
});

it('easySlowEndFromVdotResult mirrors easySlowEndSecPerKm off a VdotEstimator result', function (): void {
    expect(paceCalculator()->easySlowEndFromVdotResult(['vdot' => 30.0]))
        ->toBe(paceCalculator()->easySlowEndSecPerKm(30.0))
        ->and(paceCalculator()->easySlowEndFromVdotResult(null))->toBeNull();
});
