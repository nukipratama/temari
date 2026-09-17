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

it('reads the quality anchor for threshold and interval while easy and marathon stay on the endurance one', function (): void {
    $calculator = new TrainingPaceCalculator();

    $single = $calculator->fromVdot(28.4);
    $split = $calculator->fromVdot(28.4, 31.9);

    expect($split['easy'])->toBe($single['easy'])
        ->and($split['marathon'])->toBe($single['marathon'])
        ->and($split['threshold'])->toBeLessThan($single['threshold'])
        ->and($split['interval'])->toBeLessThan($single['interval']);
});

it('falls back to the single anchor when no quality anchor is supplied', function (): void {
    $calculator = new TrainingPaceCalculator();

    expect($calculator->fromVdotResult(['vdot' => 30.0]))
        ->toBe($calculator->fromVdot(30.0));
});

it('exposes the easy band\'s slow end without moving the averaged easy figure', function (): void {
    $paces = $this->calculator->fromVdot(29.0);

    expect($this->calculator->easySlowEndSecPerKm(29.0))
        ->toBeInt()
        ->toBeGreaterThan($paces['easy']);
});

it('produces a faster slow-end pace for a higher VDOT (monotonic)', function (): void {
    expect($this->calculator->easySlowEndSecPerKm(45.0))
        ->toBeLessThan($this->calculator->easySlowEndSecPerKm(29.0));
});

it('easySlowEndFromVdotResult mirrors easySlowEndSecPerKm off a VdotEstimator result', function (): void {
    expect($this->calculator->easySlowEndFromVdotResult(['vdot' => 30.0]))
        ->toBe($this->calculator->easySlowEndSecPerKm(30.0))
        ->and($this->calculator->easySlowEndFromVdotResult(null))->toBeNull();
});
