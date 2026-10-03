<?php

declare(strict_types=1);

use App\Models\WeeklySnapshot;
use App\Services\AI\AzureConfigCircuitBreaker;
use App\Services\AI\NarrationGate;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->gate = app(NarrationGate::class);
});

it('binds NarrationGate as scoped, so a leaked withoutDispatching() flag ends with its request', function (): void {
    expect(app(NarrationGate::class))->toBe($this->gate);

    app()->forgetScopedInstances();

    expect(app(NarrationGate::class))->not->toBe($this->gate);
});

it('never reports a spent athlete as a global pause, since nothing shared has stopped', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);

    expect($this->gate->generationPaused($snap->user_id))->toBeTrue()
        ->and($this->gate->generationPaused())->toBeFalse()
        ->and($this->gate->pauseReason())->toBeNull();
});

it('reports the app-wide ceiling as a genuine global pause', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheTotalCeilingWith($snap->user_id);

    expect($this->gate->generationPaused())->toBeTrue()
        ->and($this->gate->pauseReason())->toBe('cost_ceiling');
});

it('pauses generation and reports the config reason when the config breaker is tripped', function (): void {
    // Configured (not blank) so the reason is "config", not "unconfigured".
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'wrong-key']);

    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }

    expect($this->gate->generationPaused())->toBeTrue()
        ->and($this->gate->pauseReason())->toBe('config');
});

it('resumes generation for free once the config breaker resets (env fixed)', function (): void {
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'fixed-key']);

    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    expect($this->gate->generationPaused())->toBeTrue();

    // A successful probe (or an operator reset) closes the breaker; self-heal's
    // generationPaused() gate then clears and dispatch resumes.
    $breaker->reset();

    expect($this->gate->generationPaused())->toBeFalse()
        ->and($this->gate->pauseReason())->toBeNull();
});

it('names a reason for every stop that pauses generation', function (Closure $stop, string $reason): void {
    $stop();

    expect($this->gate->generationPaused())->toBeTrue()
        ->and($this->gate->pauseReason())->toBe($reason);
})->with([
    'kill switch off' => [
        fn () => app(AppConfig::class)->set(AppConfigKey::AiEnabled, false),
        'kill_switch',
    ],
    'auto-dispatch env switch off' => [
        fn () => config(['ai.auto_dispatch' => false]),
        'auto_dispatch',
    ],
    'azure unconfigured' => [
        fn () => config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']),
        'unconfigured',
    ],
]);

it('reports no reason while generation is running', function (): void {
    expect($this->gate->generationPaused())->toBeFalse()
        ->and($this->gate->pauseReason())->toBeNull();
});

it('reads the breaker without consuming its half-open probe when only reporting', function (): void {
    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    Carbon::setTestNow(Carbon::now()->addHour());

    expect($this->gate->pauseReason())->toBeNull()
        ->and($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_OPEN);

    expect($this->gate->generationPaused())->toBeFalse()
        ->and($breaker->state())->toBe(AzureConfigCircuitBreaker::STATE_HALF_OPEN);

    Carbon::setTestNow();
});
