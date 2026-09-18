<?php

declare(strict_types=1);

use App\Jobs\AI\SendMaintainerAlertJob;
use App\Models\AI\TokenUsage;
use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Inertia\SharedProps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sharedPropsFor(?User $user): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn (): ?User => $user);

    return app(SharedProps::class)->forRequest($request);
}

it('shares every documented key on every response', function (): void {
    expect(array_keys(sharedPropsFor(User::factory()->create())))->toBe([
        'auth',
        'flash',
        'demoLoginEnabled',
        'webPushPublicKey',
        'cartoApiKey',
        'activeRace',
        'stravaSync',
        'stravaPaused',
        'hrZonesChangedAt',
        'stravaZoneScopeMissing',
        'telegramConnected',
        'webPushSubscribed',
        'unreadNotifications',
        'aiPaused',
        'aiCatchingUp',
    ]);
});

it('keeps every derived prop a closure so a partial reload can skip it', function (): void {
    $props = sharedPropsFor(User::factory()->create());

    foreach ([
        'stravaSync',
        'activeRace', 'hrZonesChangedAt', 'telegramConnected', 'webPushSubscribed', 'unreadNotifications',
        'stravaZoneScopeMissing', 'aiPaused', 'aiCatchingUp', 'stravaPaused',
    ] as $key) {
        expect($props[$key])->toBeInstanceOf(Closure::class);
    }
});

it('exposes the signed-in user in the auth block', function (): void {
    $user = User::factory()->create(['name' => 'Nuki Pratama']);

    expect(sharedPropsFor($user)['auth']['user'])->toMatchArray([
        'id' => $user->id,
        'name' => 'Nuki Pratama',
        'is_demo' => false,
    ]);
});

it('exposes the CARTO API key for the run map, empty when unconfigured', function (): void {
    config(['services.carto.api_key' => null]);
    expect(sharedPropsFor(User::factory()->create())['cartoApiKey'])->toBe('');

    config(['services.carto.api_key' => 'test-carto-key']);
    expect(sharedPropsFor(User::factory()->create())['cartoApiKey'])->toBe('test-carto-key');
});

it('answers with safe guest defaults when nobody is signed in', function (): void {
    $props = sharedPropsFor(null);

    expect($props['auth']['user'])->toBeNull()
        ->and(($props['stravaSync'])())->toBe(['state' => 'disconnected', 'last_synced_at' => null])
        ->and(($props['activeRace'])())->toBeNull()
        ->and(($props['hrZonesChangedAt'])())->toBeNull()
        ->and(($props['telegramConnected'])())->toBeFalse()
        ->and(($props['webPushSubscribed'])())->toBeFalse()
        ->and(($props['unreadNotifications'])())->toBe(0)
        ->and(($props['stravaZoneScopeMissing'])())->toBeFalse()
        ->and(($props['aiPaused'])())->toBeFalse()
        ->and(($props['aiCatchingUp'])())->toBeFalse();
});

it('loads none of the auth user relations when no prop asks for them', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    TelegramConnection::factory()->for($user)->create();
    RunnerProfile::factory()->for($user)->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $props = sharedPropsFor($user);

    expect($props['auth']['user']['id'])->toBe($user->id)
        ->and($queries)->toBe(0)
        ->and($user->relationLoaded('telegramConnection'))->toBeFalse()
        ->and($user->relationLoaded('runnerProfile'))->toBeFalse()
        ->and($user->relationLoaded('stravaConnection'))->toBeFalse();
});

it('runs no queries at all for a guest request', function (): void {
    $props = sharedPropsFor(null);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach ($props as $key => $prop) {
        if ($key !== 'flash' && $prop instanceof Closure) {
            $prop();
        }
    }

    expect($queries)->toBe(0);
});

// #986: HandleInertiaRequests resolves every shared prop on a full page load,
// which reaches AnalysisService::ceilingExceeded() and, past the app-wide
// ceiling, a maintainer alert. That alert must never make an outbound call
// itself — it only queues one (see MaintainerAlerter::broadcast()).
it('makes no outbound call while resolving props, even when it trips a maintainer alert', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.daily_cost_ceiling_total' => 5.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 6.00, 'output_per_1m' => 10.00]]]);
    config(['services.telegram.bot_token' => 'test-bot-token']);

    $admin = User::factory()->admin()->create();
    TelegramConnection::factory()->for($admin)->create();

    $user = User::factory()->create();
    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'briefing',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'total_tokens' => 1_000_000,
        'model' => 'gpt-4o',
        'created_at' => Carbon::now(),
    ]);

    Http::fake();
    Bus::fake();

    $props = sharedPropsFor($user);

    expect(($props['aiPaused'])())->toBeTrue();
    Http::assertNothingSent();
    Bus::assertDispatched(SendMaintainerAlertJob::class);
});
