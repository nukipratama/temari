<?php

declare(strict_types=1);

use App\Services\Run\Metrics\TrainingPaceCalculator;

beforeEach(function (): void {
    $this->calculator = new TrainingPaceCalculator();
});

it('computes sane Daniels training paces for VDOT 29', function (): void {
    $paces = $this->calculator->fromVdot(29.0);

    expect($paces)->toHaveKeys(['easy', 'marathon', 'threshold', 'interval'])
        ->and($paces['easy'])->toBeInt()->toBeGreaterThanOrEqual(415)->toBeLessThanOrEqual(470)
        ->and($paces['threshold'])->toBeInt()->toBeGreaterThanOrEqual(360)->toBeLessThanOrEqual(385)
        ->and($paces['interval'])->toBeInt()->toBeGreaterThanOrEqual(330)->toBeLessThanOrEqual(355)
        ->and($paces['marathon'])->toBeInt()->toBeGreaterThanOrEqual(385)->toBeLessThanOrEqual(410);
});

it('orders paces from fastest (interval) to slowest (easy)', function (): void {
    $paces = $this->calculator->fromVdot(29.0);

    expect($paces['interval'])->toBeLessThan($paces['threshold'])
        ->and($paces['threshold'])->toBeLessThan($paces['marathon'])
        ->and($paces['marathon'])->toBeLessThan($paces['easy']);
});

it('produces faster easy pace for a higher VDOT (monotonic)', function (): void {
    $lowerVdot = $this->calculator->fromVdot(29.0);
    $higherVdot = $this->calculator->fromVdot(45.0);

    expect($higherVdot['easy'])->toBeLessThan($lowerVdot['easy'])
        ->and($higherVdot['threshold'])->toBeLessThan($lowerVdot['threshold'])
        ->and($higherVdot['interval'])->toBeLessThan($lowerVdot['interval'])
        ->and($higherVdot['marathon'])->toBeLessThan($lowerVdot['marathon']);
});
