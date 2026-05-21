<?php

declare(strict_types=1);

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lists the user\'s analyzed runs in reverse chronological order', function (): void {
    $user = User::factory()->create();
    $older = Activity::factory()->for($user)->analyzed()->create();
    $newer = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($older)->create(['name' => 'Older Run', 'start_date_local' => Carbon::now()->subDays(2)]);
    ActivityDetail::factory()->for($newer)->create(['name' => 'Newer Run', 'start_date_local' => Carbon::now()]);

    $this->actingAs($user)->get('/aktivitas')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('runs', 2)
            ->where('runs.0.detail.name', 'Newer Run')
            ->where('runs.1.detail.name', 'Older Run'));
});

it('renders the empty state when the user has no analyzed runs yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/aktivitas')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->where('runs', [])
            ->where('rangeFilter', '8w'));
});

it('excludes runs outside the requested range', function (): void {
    $user = User::factory()->create();
    $recent = Activity::factory()->for($user)->analyzed()->create();
    $ancient = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($recent)->create(['name' => 'Recent', 'start_date_local' => Carbon::now()->subDays(10)]);
    ActivityDetail::factory()->for($ancient)->create(['name' => 'Ancient', 'start_date_local' => Carbon::now()->subDays(200)]);

    // Default 8w window excludes the 200-day-old run.
    $this->actingAs($user)->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Recent'));

    // 1y window includes both.
    $this->actingAs($user)->get('/aktivitas?range=1y')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('rangeFilter', '1y'));
});

it('falls back to the default range when the query value is invalid', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/aktivitas?range=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('rangeFilter', '8w'));
});

it('returns weekly snapshots inside the range + historical for the bottom table', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->toDateString(), 'distance_km' => 30.0]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->subDays(200)->toDateString(), 'distance_km' => 10.0]);

    $this->actingAs($user)->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page
            ->has('weeklySnapshots', 1)
            ->where('weeklySnapshots.0.distance_km', 30)
            ->has('historicalSnapshots', 2));
});

it('redirects /catatan to /aktivitas', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/catatan')
        ->assertRedirect('/aktivitas');
});

it('returns null journeyMatch when the user has less than 2 activities', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->subDays(2)]);

    $this->actingAs($user)->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page->where('journeyMatch', null));
});

it('returns null pace + hr improvement when either activity lacks the underlying data', function (): void {
    $user = User::factory()->create();
    $first = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($first)->create([
        'start_date_local' => Carbon::today()->subDays(60),
        'distance' => null, // missing distance → paceSecPerKm returns null
        'moving_time' => 2100,
        'average_heartrate' => null, // missing HR
        'name' => 'First Ever',
    ]);
    $latest = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($latest)->create([
        'start_date_local' => Carbon::today()->subDays(1),
        'distance' => 5000,
        'moving_time' => 1800,
        'average_heartrate' => 150,
        'name' => 'Latest',
    ]);

    $this->actingAs($user)->get('/aktivitas?range=1y')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journeyMatch.pace_improvement_sec', null)
            ->where('journeyMatch.hr_improvement_bpm', null));
});

it('builds journeyMatch comparing first-ever and most-recent activities', function (): void {
    $user = User::factory()->create();
    $first = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($first)->create([
        'start_date_local' => Carbon::today()->subDays(60),
        'distance' => 5000,
        'moving_time' => 2100, // 7:00/km
        'average_heartrate' => 165,
        'name' => 'First Ever',
    ]);
    $latest = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($latest)->create([
        'start_date_local' => Carbon::today()->subDays(1),
        'distance' => 5000,
        'moving_time' => 1800, // 6:00/km
        'average_heartrate' => 150,
        'name' => 'Latest',
    ]);

    $this->actingAs($user)->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journeyMatch.first.name', 'First Ever')
            ->where('journeyMatch.current.name', 'Latest')
            ->where('journeyMatch.pace_improvement_sec', 60)
            ->where('journeyMatch.hr_improvement_bpm', 15));
});

it('shows a single run detail with Temari speech + run card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'name' => 'Morning Run',
        'distance' => 10000,
        'moving_time' => 3600,
        'elapsed_time' => 3600,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 60, 'Z3' => 30, 'Z4' => 10],
            'per_km' => [['km' => 1, 'pace' => '6:00', 'avg_hr' => 150]],
            'decoupling_pct' => 4.2,
        ],
    ]);
    RunCard::factory()->for($activity)->create(['special_move' => 'Paru-paru Baja']);
    StoryLine::factory()->for($activity)->create([
        'user_id' => $user->id,
        'speech' => 'Run yang solid, paru-paru baja keluar.',
    ]);

    $this->actingAs($user)->get("/aktivitas/{$activity->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('detail.name', 'Morning Run')
            ->where('storyLine.speech', 'Run yang solid, paru-paru baja keluar.')
            ->where('card.special_move', 'Paru-paru Baja'));
});

it('404s when trying to view another user\'s run', function (): void {
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $me = User::factory()->create();
    $this->actingAs($me)->get("/aktivitas/{$activity->id}")->assertNotFound();
});

it('404s when the activity has not been analyzed yet', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    $this->actingAs($user)->get("/aktivitas/{$activity->id}")->assertNotFound();
});

it('dispatches a ResolveActivityLocationJob when the run has coords but no resolved_at', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->state(['analyzed_at' => now()])->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);

    $this->actingAs($user)->get("/aktivitas/{$activity->id}")->assertSuccessful();

    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

it('does not dispatch a ResolveActivityLocationJob when already resolved', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->state(['analyzed_at' => now()])->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => 'Jakarta',
        'location_resolved_at' => now(),
    ]);

    $this->actingAs($user)->get("/aktivitas/{$activity->id}")->assertSuccessful();

    Queue::assertNotPushed(ResolveActivityLocationJob::class);
});

it('does not dispatch when the run lacks coords', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->state(['analyzed_at' => now()])->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => null,
        'start_lng' => null,
    ]);

    $this->actingAs($user)->get("/aktivitas/{$activity->id}")->assertSuccessful();

    Queue::assertNotPushed(ResolveActivityLocationJob::class);
});
