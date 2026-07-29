<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Inertia\StravaProps;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function stravaPropsFor(?User $user): array
{
    return app(StravaProps::class)->forUser($user);
}

it('keeps every prop a closure so a partial reload can skip it', function (): void {
    $props = stravaPropsFor(User::factory()->create());

    foreach (['stravaSync', 'stravaPaused', 'hrZonesChangedAt', 'stravaZoneScopeMissing'] as $key) {
        expect($props[$key])->toBeInstanceOf(Closure::class);
    }
});

it('answers with safe guest defaults when nobody is signed in', function (): void {
    $props = stravaPropsFor(null);

    expect(($props['stravaSync'])())->toBe(['state' => 'disconnected', 'last_synced_at' => null])
        ->and(($props['hrZonesChangedAt'])())->toBeNull()
        ->and(($props['stravaZoneScopeMissing'])())->toBeFalse()
        ->and(($props['stravaPaused'])())->toBeFalse();
});

it('reports the sync state the UI branches on', function (Closure $arrange, string $expected): void {
    $user = User::factory()->create();
    $arrange($user);

    expect((stravaPropsFor($user)['stravaSync'])()['state'])->toBe($expected);
})->with([
    'no connection' => [fn (User $user) => null, 'disconnected'],
    'revoked connection' => [
        fn (User $user) => StravaConnection::factory()->for($user)->create()->markRevoked(),
        'revoked',
    ],
    'connected, nothing ingested yet' => [
        fn (User $user) => StravaConnection::factory()->for($user)->create(),
        'syncing',
    ],
    'connected with an analyzed run' => [
        function (User $user): void {
            StravaConnection::factory()->for($user)->create();
            Activity::factory()->for($user)->create();
        },
        'ready',
    ],
]);

it('exposes the most recent Strava pull as last_synced_at', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-18 09:00:00')]);

    expect((stravaPropsFor($user)['stravaSync'])()['last_synced_at'])
        ->toBe(Carbon::parse('2026-05-18 09:00:00')->toIso8601String());
});

it('flags a live connection that never granted the zone scope', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    expect((stravaPropsFor($user)['stravaZoneScopeMissing'])())->toBeTrue();
});

it('does not flag a connection that granted the zone scope', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    expect((stravaPropsFor($user)['stravaZoneScopeMissing'])())->toBeFalse();
});

it('never nudges the demo user, which does not sync zones from Strava', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    expect((stravaPropsFor($user)['stravaZoneScopeMissing'])())->toBeFalse();
});

it('returns a null zone marker when the user has no runner profile', function (): void {
    expect((stravaPropsFor(User::factory()->create())['hrZonesChangedAt'])())->toBeNull();
});

it('returns the stored zone-change marker as ISO-8601', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 190]);

    expect((stravaPropsFor($user->fresh())['hrZonesChangedAt'])())->toBeString();
});

it('mirrors the Strava kill-switch into stravaPaused', function (bool $enabled, bool $paused): void {
    Cache::flush();
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, $enabled);

    expect((stravaPropsFor(User::factory()->create())['stravaPaused'])())->toBe($paused);
})->with([
    'kill-switch on' => [true, false],
    'kill-switch off' => [false, true],
]);
