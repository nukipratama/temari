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
    expect(Cooldown::notificationKey(42))->toBe('notification-send:42');
});

it('attempt starts the window and returns true when not already active', function (): void {
    $cooldown = new Cooldown('probe');

    expect($cooldown->attempt())->toBeTrue();
    expect($cooldown->isActive())->toBeTrue();
});

it('attempt returns false and leaves the window untouched when already active', function (): void {
    $cooldown = new Cooldown('probe');
    $cooldown->start();
    $remainingBefore = $cooldown->remaining();

    expect($cooldown->attempt())->toBeFalse();
    expect($cooldown->remaining())->toBe($remainingBefore);
});
