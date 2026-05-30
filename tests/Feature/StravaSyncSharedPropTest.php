<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares a disconnected strava-sync payload when the user has no connection', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stravaSync.connected', false)
            ->where('stravaSync.last_synced_at', null));
});

it('shares the latest fetched_at as last_synced_at when connected', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-01 03:00:00')]);
    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-20 04:00:00')]);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stravaSync.connected', true)
            ->where('stravaSync.last_synced_at', Carbon::parse('2026-05-20 04:00:00')->toIso8601String()));
});

it('caches the strava-sync payload under a per-user key', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-20 04:00:00')]);

    $this->actingAs($user)->get('/rekor')->assertSuccessful();

    expect(Cache::has("strava-sync:{$user->id}"))->toBeTrue();

    // A newer sync after the cache warms must NOT surface until the TTL lapses.
    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-25 09:00:00')]);

    $this->actingAs($user)->get('/rekor')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stravaSync.last_synced_at', Carbon::parse('2026-05-20 04:00:00')->toIso8601String()));
});
