<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders Profile with computed identity + hero stats', function (): void {
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
    foreach ($analyzed as $idx => $activity) {
        ActivityDetail::factory()->for($activity)->create([
            'distance' => $idx === 0 ? 5000 : 8000, // longest = 8 km
            'start_date_local' => Carbon::today()->subDays(10 - $idx),
        ]);
    }

    // un-analyzed activity should be excluded from the COUNT + SUM
    $unanalyzed = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($unanalyzed)->create(['distance' => 99000]);

    $this->actingAs($user)->get('/profil')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('stats.total_runs', 2)
            ->where('stats.total_km', 13)
            ->where('stats.longest_run_km', 8)
            ->where('identity.strava_connected', true));
});

it('reports strava_connected as false when the user has no connection', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profil')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('identity.strava_connected', false)
            ->where('stats.total_runs', 0)
            ->where('stats.longest_run_km', 0));
});

it('returns up to 3 most recent PRs with activity context when available', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->state(['analyzed_at' => now()])->create();
    ActivityDetail::factory()->for($activity)->create(['name' => 'Morning 5k']);

    foreach (['1km', '5km', '10km', 'half_marathon'] as $idx => $category) {
        PersonalRecord::factory()->for($user)->create([
            'category' => $category,
            'value_sec' => 300 + $idx * 60,
            'set_at' => Carbon::today()->subDays(10 - $idx),
            'activity_id' => $category === '5km' ? $activity->id : null,
        ]);
    }

    $this->actingAs($user)->get('/profil')
        ->assertInertia(fn (Assert $page) => $page
            ->has('topPrs', 3)
            ->where('topPrs.0.category', 'half_marathon')
            ->where('topPrs.2.category', '5km')
            ->where('topPrs.2.activity_name', 'Morning 5k'));
});

it('requires auth', function (): void {
    $this->get('/profil')->assertRedirect('/login');
});
