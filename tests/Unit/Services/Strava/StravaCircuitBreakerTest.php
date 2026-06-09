<?php

declare(strict_types=1);

use App\Services\Strava\StravaCircuitBreaker;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function breaker(): StravaCircuitBreaker
{
    return new StravaCircuitBreaker(new AppConfig());
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('starts closed and allows requests', function (): void {
    $breaker = breaker();

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_CLOSED)
        ->and($breaker->allowsRequest())->toBeTrue();
});

it('opens after the failure threshold and then blocks requests', function (): void {
    $breaker = breaker();

    // Default threshold is 5.
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_OPEN)
        ->and($breaker->allowsRequest())->toBeFalse();
});

it('does not open before the threshold is reached', function (): void {
    $breaker = breaker();

    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_CLOSED)
        ->and($breaker->allowsRequest())->toBeTrue();
});

it('half-opens after the cooldown and allows a single probe', function (): void {
    Carbon::setTestNow('2026-06-09 10:00:00');
    $breaker = breaker();

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->allowsRequest())->toBeFalse();

    // Past the 300s default cooldown.
    Carbon::setTestNow('2026-06-09 10:06:00');

    expect($breaker->allowsRequest())->toBeTrue()
        ->and($breaker->state())->toBe(StravaCircuitBreaker::STATE_HALF_OPEN);
});

it('closes again when a half-open probe succeeds', function (): void {
    Carbon::setTestNow('2026-06-09 10:00:00');
    $breaker = breaker();

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    Carbon::setTestNow('2026-06-09 10:06:00');
    $breaker->allowsRequest(); // transitions to half-open

    $breaker->recordSuccess();

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_CLOSED)
        ->and((new AppConfig())->integer(AppConfigKey::StravaBreakerFailures))->toBe(0);
});

it('re-opens when a half-open probe fails', function (): void {
    Carbon::setTestNow('2026-06-09 10:00:00');
    $breaker = breaker();

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    Carbon::setTestNow('2026-06-09 10:06:00');
    $breaker->allowsRequest(); // half-open

    $breaker->recordFailure();

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_OPEN)
        ->and($breaker->allowsRequest())->toBeFalse(); // cooldown restarted at 10:06
});

it('a success in the closed state resets the failure streak', function (): void {
    $breaker = breaker();

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordSuccess();

    expect((new AppConfig())->integer(AppConfigKey::StravaBreakerFailures))->toBe(0);

    // ...so it now takes a full threshold of fresh failures to open.
    for ($i = 0; $i < 4; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_CLOSED);
});

it('reset() force-closes an open breaker', function (): void {
    $breaker = breaker();
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_OPEN);

    $breaker->reset();

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_CLOSED)
        ->and($breaker->allowsRequest())->toBeTrue()
        ->and($breaker->snapshot()['opened_at'])->toBeNull();
});

it('honors a runtime-tuned threshold', function (): void {
    (new AppConfig())->set(AppConfigKey::StravaBreakerThreshold, 2);
    $breaker = breaker();

    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_OPEN);
});
