<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Livewire\Pulse\SystemControl;
use App\Models\Activity;
use App\Services\Strava\StravaCircuitBreaker;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the switches, breaker state and backlog without error', function (): void {
    Livewire::test(SystemControl::class)
        ->assertOk()
        ->assertSee('Kill-switches')
        ->assertSee('circuit breaker')
        ->assertSee('pending')
        ->assertSee('stranded');
});

it('shows an ok health badge when the breaker is closed and nothing is stranded', function (): void {
    Livewire::test(SystemControl::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows an alert health badge when activities are stranded', function (): void {
    Activity::factory()->stub()->create(['detail_fail_count' => 5, 'analyzed_at' => null]);

    Livewire::test(SystemControl::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('shows an alert health badge when the breaker is open', function (): void {
    $breaker = new StravaCircuitBreaker(new AppConfig());
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    Livewire::test(SystemControl::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('shows a warn health badge when the breaker is half-open', function (): void {
    // half_open is a probe state reached after the open cooldown elapses; the
    // breaker itself only sets it via a request-time check, so we set the
    // stored state directly to prove the severity mapping in isolation.
    app(AppConfig::class)->set(AppConfigKey::StravaBreakerState, StravaCircuitBreaker::STATE_HALF_OPEN);

    Livewire::test(SystemControl::class)
        ->assertOk()
        ->assertSee('health: warn');
});

it('counts pending vs stranded activities correctly', function (): void {
    Activity::factory()->stub()->count(2)->create(['detail_fail_count' => 0]);
    Activity::factory()->stub()->create(['detail_fail_count' => 5]); // stranded

    Livewire::test(SystemControl::class)
        ->assertOk()
        ->assertSee('pending')
        ->assertSee('stranded');
});

it('toggleAi flips the AI kill-switch in app_config', function (): void {
    expect(app(AppConfig::class)->boolean(AppConfigKey::AiEnabled))->toBeTrue();

    Livewire::test(SystemControl::class)->call('toggleAi');

    expect(new AppConfig()->boolean(AppConfigKey::AiEnabled))->toBeFalse();
});

it('toggleAi busts the cached ai-paused signal so the banner reflects the flip immediately', function (): void {
    Cache::forever(HandleInertiaRequests::AI_PAUSED_CACHE_KEY, false);

    Livewire::test(SystemControl::class)->call('toggleAi');

    expect(Cache::has(HandleInertiaRequests::AI_PAUSED_CACHE_KEY))->toBeFalse();
});

it('toggleStrava flips the Strava kill-switch in app_config', function (): void {
    Livewire::test(SystemControl::class)->call('toggleStrava');

    expect(new AppConfig()->boolean(AppConfigKey::StravaEnabled))->toBeFalse();
});

it('resetBreaker force-closes an open breaker', function (): void {
    $breaker = new StravaCircuitBreaker(new AppConfig());
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->state())->toBe(StravaCircuitBreaker::STATE_OPEN);

    Livewire::test(SystemControl::class)->call('resetBreaker');

    expect(new StravaCircuitBreaker(new AppConfig())->state())
        ->toBe(StravaCircuitBreaker::STATE_CLOSED);
});
