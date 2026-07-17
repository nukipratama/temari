<?php

declare(strict_types=1);

use App\Services\Run\Story\VibeMatrix;

function signals(array $overrides = []): array
{
    return array_merge([
        'form' => 0.0,
        'form_status' => 'optimal',
        'days_since_run' => 1,
        'recent_pr' => false,
        'decoupling_avg' => null,
    ], $overrides);
}

it('picks hibernating when the user has been inactive 10+ days', function (): void {
    expect(new VibeMatrix()->pick(signals(['days_since_run' => 14])))->toBe('hibernating');
});

it('picks hibernating when there is no prior run at all', function (): void {
    expect(new VibeMatrix()->pick(signals(['days_since_run' => null])))->toBe('hibernating');
});

it('picks pumped on a recent PR with non-negative form', function (): void {
    expect(new VibeMatrix()->pick(signals(['recent_pr' => true, 'form' => 3.0])))->toBe('pumped');
});

it('still picks worn-down if recent PR coincides with bad form', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'recent_pr' => true,
        'form' => -25.0,
        'form_status' => 'fatigued',
    ])))->toBe('worn_down');
});

it('picks fresh on a fresh status (typical taper)', function (): void {
    expect(new VibeMatrix()->pick(signals(['form_status' => 'fresh', 'form' => 15.0])))->toBe('fresh');
});

it('picks stretched-thin on overreaching + high aerobic decoupling', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'overreaching',
        'form' => -40.0,
        'decoupling_avg' => 8.5,
    ])))->toBe('stretched_thin');
});

it('treats decoupling of exactly 5.0 as cooked, not stretched-thin (boundary is strictly above)', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'overreaching',
        'form' => -40.0,
        'decoupling_avg' => 5.0,
    ])))->toBe('cooked');
});

it('picks stretched-thin once decoupling is just past 5.0', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'overreaching',
        'form' => -40.0,
        'decoupling_avg' => 5.01,
    ])))->toBe('stretched_thin');
});

it('picks cooked on overreaching with healthy aerobic system', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'overreaching',
        'form' => -40.0,
        'decoupling_avg' => 1.5,
    ])))->toBe('cooked');
});

it('picks worn-down on fatigued status', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'fatigued',
        'form' => -20.0,
    ])))->toBe('worn_down');
});

it('picks bouncy on optimal form + negative decoupling (aerobic system humming)', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'optimal',
        'form' => 2.0,
        'decoupling_avg' => -1.2,
    ])))->toBe('bouncy');
});

it('falls back to steady when no other rule fires', function (): void {
    expect(new VibeMatrix()->pick(signals([
        'form_status' => 'optimal',
        'form' => -3.0,
        'decoupling_avg' => 2.0,
    ])))->toBe('steady');
});
