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

it('builds a per-user key for the test send, since a test has no subject', function (): void {
    expect(Cooldown::testNotificationKey(7))->toBe('notification-test:7');
});

/**
 * The two uses want different lengths for different reasons: a re-send protects
 * the recipient from being buzzed twice, while the test send protects nobody and
 * is pressed exactly when someone is setting a channel up and iterating.
 */
it('honours a per-instance window rather than the shared default', function (): void {
    $cooldown = new Cooldown('custom-window', Cooldown::TEST_WINDOW_SECONDS);
    $cooldown->start();

    expect($cooldown->remaining())
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(Cooldown::TEST_WINDOW_SECONDS)
        ->and(Cooldown::TEST_WINDOW_SECONDS)->toBeLessThan(Cooldown::WINDOW_SECONDS);
});

/**
 * These three are separate on purpose and the ordering is the point. The default
 * window guards a paid LLM re-narration; the notification window guards a
 * duplicate buzz for a narration that already exists; the test window guards
 * nothing and exists only so setup-time iteration stays quick. Collapsing them
 * back into one constant would silently retune whichever guard was not being
 * thought about.
 */
it('keeps the AI re-narration guard longest, since it is the one that costs money', function (): void {
    expect(Cooldown::WINDOW_SECONDS)->toBe(900)
        ->and(Cooldown::NOTIFICATION_WINDOW_SECONDS)->toBe(300)
        ->and(Cooldown::TEST_WINDOW_SECONDS)->toBe(60)
        ->and(Cooldown::NOTIFICATION_WINDOW_SECONDS)->toBeLessThan(Cooldown::WINDOW_SECONDS)
        ->and(Cooldown::TEST_WINDOW_SECONDS)->toBeLessThan(Cooldown::NOTIFICATION_WINDOW_SECONDS);
});
