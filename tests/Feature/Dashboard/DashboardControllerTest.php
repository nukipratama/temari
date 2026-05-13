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
            ->component('Dashboard')
            ->where('auth.user.first_name', explode(' ', (string) $user->name)[0])
            ->where('load', null)
            ->where('recentRuns', []));
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
            ->component('Dashboard')
            ->has('load.weekly_trimp')
            ->has('load.form')
            ->has('snapshot')
            ->has('recentRuns', 5));

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
