<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
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

it('flags hasNewPr=true for a fresh unseen PR without writing during the GET render', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create(['last_seen_pr_ledger_at' => null]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    PersonalRecord::factory()->for($user)->create([
        'activity_id' => $activity->id,
        'set_at' => Carbon::today()->subHour(),
    ]);

    // The GET only DETECTS the PR; it must stay read-only (no marker write).
    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('hasNewPr', true));
    expect($user->refresh()->last_seen_pr_ledger_at)->toBeNull();

    // A second GET still detects it as unseen — the marker never advanced on GET.
    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('hasNewPr', true));
    expect($user->refresh()->last_seen_pr_ledger_at)->toBeNull();

    Carbon::setTestNow();
});

it('returns null weekVsLastWeek when the user has fewer than 2 weekly snapshots', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 25,
        'runs' => 3,
        'weekly_trimp' => 150,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('weekVsLastWeek', null));
});

it('computes weekVsLastWeek deltas when two consecutive weekly snapshots exist', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->toDateString(),
        'distance_km' => 20,
        'runs' => 3,
        'weekly_trimp' => 200,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 25,
        'runs' => 4,
        'weekly_trimp' => 220,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('weekVsLastWeek.this_week_km', 25)
            ->where('weekVsLastWeek.this_week_runs', 4)
            ->where('weekVsLastWeek.distance_delta_km', 5)
            ->where('weekVsLastWeek.runs_delta', 1));
});

it('falls back to null pace_delta when one snapshot lacks distance / runs / trimp', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->toDateString(),
        'distance_km' => null,
        'runs' => null,
        'weekly_trimp' => null,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 25,
        'runs' => 4,
        'weekly_trimp' => 220,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('weekVsLastWeek.pace_delta_sec', null));
});

it('falls back to null pace_delta when a snapshot lacks moving_time_sec', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->toDateString(),
        'distance_km' => 20,
        'runs' => 3,
        'moving_time_sec' => null, // snapshot written before moving time was tracked
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 25,
        'runs' => 4,
        'moving_time_sec' => 8250,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('weekVsLastWeek.pace_delta_sec', null));
});

it('computes real pace_delta_sec from moving time over distance', function (): void {
    $user = User::factory()->create();
    // last week: 20 km in 7200s → 360 s/km.
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->toDateString(),
        'distance_km' => 20,
        'moving_time_sec' => 7200,
    ]);
    // this week: 25 km in 8250s → 330 s/km (30 s/km faster).
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 25,
        'moving_time_sec' => 8250,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('weekVsLastWeek.pace_delta_sec', -30));
});

it('surfaces the latest activity with un-dismissed milestone payload', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create([
        'milestone_payload' => [
            ['kind' => 'pr', 'label' => 'PR!', 'body' => 'PR di 5km.', 'priority' => 100],
        ],
        'milestones_detected_at' => now(),
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingMilestone.activity_id')
            ->has('pendingMilestone.milestones', 1));
});

it('returns null pendingMilestone when payload is null', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create(['milestone_payload' => null]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('pendingMilestone', null));
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
