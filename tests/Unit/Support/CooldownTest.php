<?php

declare(strict_types=1);

use App\Support\Cooldown;
use Illuminate\Support\Facades\RateLimiter;

afterEach(function (): void {
    RateLimiter::clear('probe');
});

it('is inactive before it is started', function (): void {
    $cooldown = new Cooldown('probe');

    expect($cooldown->isActive())->toBeFalse();
    expect($cooldown->remaining())->toBeNull();
});

it('becomes active for the window after start', function (): void {
    $cooldown = new Cooldown('probe');
    $cooldown->start();

    expect($cooldown->isActive())->toBeTrue();
    expect($cooldown->remaining())
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('clears once the window is released', function (): void {
    $cooldown = new Cooldown('probe');
    $cooldown->start();
    RateLimiter::clear('probe');

    expect($cooldown->isActive())->toBeFalse();
    expect($cooldown->remaining())->toBeNull();
});

it('builds a per-analysis telegram key', function (): void {
    expect(Cooldown::telegramKey(42))->toBe('telegram-send:42');
});
