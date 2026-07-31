<?php

declare(strict_types=1);

use App\Exceptions\AI\TransientUpstreamException;
use App\Services\AI\AzureCallThrottle;
use Illuminate\Support\Facades\RateLimiter;

it('proceeds immediately without sleeping when under the local rate limit', function (): void {
    config(['ai.azure_calls_per_minute' => 5]);
    $sleeps = [];
    $throttle = new AzureCallThrottle(function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });

    $throttle->block();

    expect($sleeps)->toBe([]);
});

it('blocks once, then proceeds once the sleep step frees the window', function (): void {
    config(['ai.azure_calls_per_minute' => 1]);
    RateLimiter::hit('azure-openai-calls', 60); // consume the only slot

    $sleeps = [];
    // Simulates the wait actually freeing the window (real sleep() would let
    // the real decay elapse); the fake just clears the bucket instead of
    // waiting in real time.
    $throttle = new AzureCallThrottle(function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
        RateLimiter::clear('azure-openai-calls');
    });

    $throttle->block();

    expect($sleeps)->toHaveCount(1);
});

it('throws TransientUpstreamException once the block cap is exceeded under sustained oversubscription', function (): void {
    config(['ai.azure_calls_per_minute' => 1]);
    RateLimiter::hit('azure-openai-calls', 60); // consume the only slot, never freed

    $sleeps = [];
    // Never frees the window — simulates a sweep so oversubscribed the local
    // budget never has a free slot within the block cap.
    $throttle = new AzureCallThrottle(function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });

    expect(fn () => $throttle->block())->toThrow(TransientUpstreamException::class);
    // The 60s RateLimiter decay window is shorter than the 90s default block
    // cap, so it takes two capped sleeps (60, then the remaining 30) to
    // exhaust the cap before giving up — fast and deterministic regardless of
    // the real decay window's length.
    expect($sleeps)->toBe([60, 30]);
});

it('respects a configured block cap', function (): void {
    config(['ai.azure_calls_per_minute' => 1, 'ai.azure_block_cap_seconds' => 45]);
    RateLimiter::hit('azure-openai-calls', 60); // consume the only slot, never freed

    $sleeps = [];
    $throttle = new AzureCallThrottle(function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });

    expect(fn () => $throttle->block())->toThrow(TransientUpstreamException::class);
    expect($sleeps)->toHaveCount(1)->and($sleeps[0])->toBe(45);
});

it('carries the last observed availableIn as retryAfterSeconds so the job release delay is informed', function (): void {
    config(['ai.azure_calls_per_minute' => 1]);
    RateLimiter::hit('azure-openai-calls', 60);

    $throttle = new AzureCallThrottle(function (int $seconds): void {
    });

    try {
        $throttle->block();
        expect(false)->toBeTrue('expected TransientUpstreamException');
    } catch (TransientUpstreamException $e) {
        expect($e->retryAfterSeconds)->not->toBeNull()
            ->and($e->retryAfterSeconds)->toBeGreaterThan(0);
    }
});

it('respects a configured lower per-minute ceiling', function (): void {
    config(['ai.azure_calls_per_minute' => 2]);
    $sleeps = [];
    $throttle = new AzureCallThrottle(function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });

    // Two calls fit under the ceiling of 2/min without blocking.
    $throttle->block();
    $throttle->block();

    expect($sleeps)->toBe([]);
});
