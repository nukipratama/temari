<?php

declare(strict_types=1);

use App\Services\AI\AzureConfigCircuitBreaker;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function configBreaker(): AzureConfigCircuitBreaker
{
    return new AzureConfigCircuitBreaker(new AppConfig());
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('starts closed, allows requests, and is not tripped', function (): void {
    $breaker = configBreaker();

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_CLOSED)
        ->and($breaker->allowsRequest())->toBeTrue()
        ->and($breaker->isTripped())->toBeFalse();
});

it('opens after the default threshold of 3 consecutive failures and blocks + reports tripped', function (): void {
    $breaker = configBreaker();

    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_OPEN)
        ->and($breaker->allowsRequest())->toBeFalse()
        ->and($breaker->isTripped())->toBeTrue();
});

it('does not open before the threshold is reached', function (): void {
    $breaker = configBreaker();

    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_CLOSED)
        ->and($breaker->allowsRequest())->toBeTrue();
});

it('fails open when state is open but opened_at is missing (corrupt/partial config)', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::AiConfigBreakerState, AzureConfigCircuitBreaker::STATE_OPEN);
    $breaker = new AzureConfigCircuitBreaker($config);

    expect($breaker->allowsRequest())->toBeTrue()
        ->and($breaker->isTripped())->toBeFalse();
});

it('half-opens after the cooldown and allows a single probe', function (): void {
    Carbon::setTestNow('2026-06-09 10:00:00');
    $breaker = configBreaker();

    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->allowsRequest())->toBeFalse();

    // Past the 900s default cooldown.
    Carbon::setTestNow('2026-06-09 10:16:00');

    expect($breaker->isTripped())->toBeFalse()
        ->and($breaker->allowsRequest())->toBeTrue()
        ->and($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_HALF_OPEN);
});

it('closes again when a half-open probe succeeds (env fixed, auto-resume)', function (): void {
    Carbon::setTestNow('2026-06-09 10:00:00');
    $breaker = configBreaker();

    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    Carbon::setTestNow('2026-06-09 10:16:00');
    $breaker->allowsRequest(); // -> half-open

    $breaker->recordSuccess();

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_CLOSED)
        ->and((new AppConfig())->integer(AppConfigKey::AiConfigBreakerFailures))->toBe(0);
});

it('re-opens when a half-open probe fails again', function (): void {
    Carbon::setTestNow('2026-06-09 10:00:00');
    $breaker = configBreaker();

    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    Carbon::setTestNow('2026-06-09 10:16:00');
    $breaker->allowsRequest(); // -> half-open

    $breaker->recordFailure();

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_OPEN)
        ->and($breaker->allowsRequest())->toBeFalse();
});

it('a success in the closed state resets the failure streak', function (): void {
    $breaker = configBreaker();

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordSuccess();

    expect((new AppConfig())->integer(AppConfigKey::AiConfigBreakerFailures))->toBe(0);
});

it('reset() force-closes an open breaker', function (): void {
    $breaker = configBreaker();
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_OPEN);

    $breaker->reset();

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_CLOSED)
        ->and($breaker->allowsRequest())->toBeTrue()
        ->and($breaker->snapshot()['opened_at'])->toBeNull();
});

it('honors a runtime-tuned threshold', function (): void {
    (new AppConfig())->set(AppConfigKey::AiConfigBreakerThreshold, 2);
    $breaker = configBreaker();

    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_OPEN);
});
