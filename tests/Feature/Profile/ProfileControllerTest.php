<?php

declare(strict_types=1);

use App\Models\StoryLine;
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
        ->analyzed()
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
            ->component('Aku')
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
            ->component('Aku')
            ->where('identity.strava_connected', false)
            ->where('stats.total_runs', 0)
            ->where('stats.longest_run_km', 0));
});

it('requires auth', function (): void {
    $this->get('/profil')->assertRedirect('/login');
});

it('includes training_paces derived from VDOT when the user has a qualifying PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
    ]);

    $this->actingAs($user)->get('/profil')
        ->assertInertia(fn (Assert $page) => $page
            ->has('fitness.training_paces.easy')
            ->has('fitness.training_paces.marathon')
            ->has('fitness.training_paces.threshold')
            ->has('fitness.training_paces.interval'));
});

it('reports null training_paces when the user has no VDOT-eligible PR', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profil')
        ->assertInertia(fn (Assert $page) => $page
            ->where('fitness', null));
});

it('exposes personaMix derived from StoryLine moods + personaSummary payload', function (): void {
    $user = User::factory()->create();
    $a = Activity::factory()->for($user)->analyzed()->create();
    StoryLine::factory()->for($user)->create([
        'activity_id' => $a->id,
        'mood' => 'nyala',
    ]);

    $this->actingAs($user)->get('/profil')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Aku')
            ->has('personaMix', 1)
            ->where('personaMix.0.mood', 'nyala')
            ->where('personaMix.0.percent', 100)
            ->has('personaSummary')
            ->where('personaSummary.type', 'persona_summary')
            ->where('personaSummary.subject_type', 'persona_summary_user'));
});
