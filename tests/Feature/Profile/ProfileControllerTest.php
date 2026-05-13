<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders Profile with computed stats + strava info', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'strava_athlete_id' => 12345,
        'scopes' => 'read,activity:read',
    ]);

    $analyzed = Activity::factory()
        ->for($user)
        ->state(['analyzed_at' => now()])
        ->count(2)
        ->create();
    foreach ($analyzed as $activity) {
        ActivityDetail::factory()->for($activity)->create(['distance' => 5000]);
    }

    // un-analyzed activity should be excluded from the COUNT + SUM
    $unanalyzed = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($unanalyzed)->create(['distance' => 99000]);

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('stats.total_runs', 2)
            ->where('stats.total_km', 10)
            ->where('strava.athlete_id', 12345));
});

it('returns null strava when the user has no connection', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('strava', null)
            ->where('stats.total_runs', 0));
});

it('requires auth', function (): void {
    $this->get('/profile')->assertRedirect('/login');
});
