<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Inertia\SharedProps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        'equippedAccessories',
        'pendingReveal',
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
        'equippedAccessories', 'pendingReveal', 'stravaSync',
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
        ->and(($props['aiCatchingUp'])())->toBeFalse()
        ->and(($props['pendingReveal'])())->toBeNull()
        ->and(($props['equippedAccessories'])())->toBe([
            'medal' => null,
            'headband' => null,
            'shirt' => null,
            'shorts' => null,
            'shoes' => null,
            'aura' => null,
        ]);
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
