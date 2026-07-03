<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects unauthenticated users to login', function (): void {
    $this->get('/')->assertRedirect('/login');
});

it('renders for a user with no synced activities', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HariIni')
            ->where('auth.user.first_name', explode(' ', (string) $user->name)[0])
            ->where('load', null)
            ->where('recentRuns', []));
});

it('includes the route polyline + stream summary on recent runs so the cards draw routes', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 70, 'Z3' => 20]],
    ]);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentRuns.0.summary_polyline', '_p~iF~ps|U_ulLnnqC_mqNvxq`@')
            ->has('recentRuns.0.stream_summary'));
});

it('ships the persisted post-run mood per recent run for the featured card + last-run mascot', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'enteng']);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where("recentMoods.{$activity->id}", 'enteng'));
});

it('renders KPIs + recent runs when the user has training-load history', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create();

    for ($i = 0; $i < 80; $i++) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today()->subDays(79 - $i),
        ]);
    }

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 35.0,
        'runs' => 4,
    ]);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HariIni')
            ->has('load.weekly_trimp')
            ->has('load.form')
            ->has('snapshot')
            ->has('recentRuns', 8));

    Carbon::setTestNow();
});

it('includes a shaped weeklyRecap prop with the current-week range and zeroed stats for a fresh user', function (): void {
    Carbon::setTestNow('2026-05-13 12:00:00'); // Wednesday → week Mon 05-11 .. Sun 05-17.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('weeklyRecap.week_start', '2026-05-11')
            ->where('weeklyRecap.week_end', '2026-05-17')
            ->where('weeklyRecap.this_week_km', 0)
            ->where('weeklyRecap.this_week_runs', 0)
            ->where('weeklyRecap.delta_pct', null)
            ->where('weeklyRecap.streak_weeks', 0)
            ->where('weeklyRecap.best_card', null)
            ->has('weeklyRecap.nearest_goal'));

    Carbon::setTestNow();
});

it('populates weeklyRecap km, runs, and delta from this and last weeks snapshots', function (): void {
    Carbon::setTestNow('2026-05-13 12:00:00');
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10',
        'distance_km' => 20.0,
        'runs' => 3,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'distance_km' => 25.0,
        'runs' => 4,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('weeklyRecap.this_week_km', 25)
            ->where('weeklyRecap.this_week_runs', 4)
            ->where('weeklyRecap.delta_pct', 25));

    Carbon::setTestNow();
});

it('reuses the same daily greeting on a second open within the day', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertSuccessful();
    $this->actingAs($user)->get('/')->assertSuccessful();

    expect(StoryLine::query()
        ->where('user_id', $user->id)
        ->where('kind', StoryLine::KIND_DAILY_GREETING)
        ->where('for_date', '2026-05-11')
        ->count())->toBe(1);

    Carbon::setTestNow();
});
