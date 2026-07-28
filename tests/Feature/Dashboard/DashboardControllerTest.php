<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

it('does not ship the unused trendAnalysis or weeklyRecap props', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HariIni')
            ->missing('trendAnalysis')
            ->missing('weeklyRecap'));
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

/**
 * Every briefing trigger on this page (`SuggestionCard`, `KataTemariCompact`,
 * `FeaturedKartuPanel`) polls `router.reload({ only: ['briefing'] })` every
 * 3-15s while the analysis generates. Every prop used to be computed in the
 * method body, so each tick re-ran the eight-row recent-run fetch — polylines
 * and stream summaries included — plus the weekly-snapshot read, for props the
 * response then discarded. Behind closures, Inertia skips them.
 */
it('does not fetch recent runs or weekly snapshots on a briefing-only partial reload', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    WeeklySnapshot::factory()->for($user)->create();

    $headers = briefingOnlyHeaders($this->actingAs($user));

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->actingAs($user)->get('/', $headers)->assertSuccessful();

    // `summary_polyline` is unique to the recent-run select, which `recentRuns`,
    // `lastRunNote` and `recentMoods` all share behind one memoized closure.
    $recentRunFetches = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'summary_polyline'));
    $snapshotReads = array_filter($queries, fn (string $sql): bool => str_contains($sql, '`weekly_snapshots`'));

    expect($recentRunFetches)->toBeEmpty()
        ->and($snapshotReads)->toBeEmpty();

    $response->assertJsonPath('component', 'HariIni');
    foreach (['load', 'snapshot', 'recentRuns', 'lastRunNote', 'recentMoods'] as $skipped) {
        $response->assertJsonMissingPath("props.{$skipped}");
    }
});

it('still returns every dashboard prop on a full page load', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    WeeklySnapshot::factory()->for($user)->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HariIni')
            ->has('briefing')
            ->has('snapshot')
            ->has('recentRuns', 1)
            ->has('recentMoods'));
});

/**
 * Partial-reload headers mimicking the briefing poller's
 * `router.reload({ only: ['briefing'] })`. See `inertiaVersionFor` in
 * tests/Feature/Runs/RunControllerTest.php for why the version is read off a
 * real HTML response.
 *
 * @param  object  $actingAs  The authenticated test case.
 * @return array<string, string>
 */
function briefingOnlyHeaders(object $actingAs): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersionFor($actingAs, '/'),
        'X-Inertia-Partial-Component' => 'HariIni',
        'X-Inertia-Partial-Data' => 'briefing',
    ];
}
