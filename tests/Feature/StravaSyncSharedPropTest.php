<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('caches the strava-sync payload under a per-user key', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-20 04:00:00')]);

    $this->actingAs($user)->get('/profile')->assertSuccessful();

    expect(Cache::has(SharedPropCacheKey::StravaSync->key($user->id)))->toBeTrue();

    // A newer sync after the cache warms must NOT surface until the TTL lapses.
    Activity::factory()->for($user)->create(['fetched_at' => Carbon::parse('2026-05-25 09:00:00')]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stravaSync.last_synced_at', Carbon::parse('2026-05-20 04:00:00')->toIso8601String()));
});
