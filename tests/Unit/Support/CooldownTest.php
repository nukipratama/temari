<?php

declare(strict_types=1);

use App\Support\Cooldown;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

it('remainingMany reports exactly what remaining() reports for each key', function (): void {
    $started = new Cooldown('batch-a', 900);
    $started->start();
    new Cooldown('batch-b', 60)->start();
    // 'batch-c' is never started, so it has no timer entry at all.

    $keys = ['batch-a', 'batch-b', 'batch-c'];
    $batched = Cooldown::remainingMany($keys);

    $perKey = [];
    foreach ($keys as $key) {
        $perKey[$key] = new Cooldown($key)->remaining();
    }

    expect($batched)->toBe($perKey)
        ->and($batched['batch-a'])->toBeGreaterThan(0)
        ->and($batched['batch-b'])->toBeGreaterThan(0)
        ->and($batched['batch-c'])->toBeNull();

    RateLimiter::clear('batch-a');
    RateLimiter::clear('batch-b');
});

it('remainingMany reads every key in a single cache round trip', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $now = Carbon::now()->getTimestamp();

    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('many')
        ->once()
        ->with(['one:timer', 'two:timer', 'three:timer'])
        ->andReturn([
            'one:timer' => $now + 42,
            'two:timer' => null,
            'three:timer' => $now - 5,
        ]);
    Cache::shouldReceive('driver')->once()->andReturn($repository);

    $remaining = Cooldown::remainingMany(['one', 'two', 'three']);

    // An elapsed timer reads as "not cooling", exactly as availableIn()'s
    // max(0, ...) does, rather than as a negative countdown.
    expect($remaining)->toBe(['one' => 42, 'two' => null, 'three' => null]);

    Carbon::setTestNow();
});

it('remainingMany looks a repeated key up only once and never touches the cache for none', function (): void {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('many')->once()->with(['dupe:timer'])->andReturn(['dupe:timer' => null]);
    Cache::shouldReceive('driver')->once()->andReturn($repository);

    expect(Cooldown::remainingMany(['dupe', 'dupe', 'dupe']))->toBe(['dupe' => null]);
    expect(Cooldown::remainingMany([]))->toBe([]);
});
