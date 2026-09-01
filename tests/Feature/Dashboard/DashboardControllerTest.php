<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\PastYouTrendBuilder;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('never renders the dashboard to a guest', function (): void {
    $this->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('renders for a user with no synced activities', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user.first_name', explode(' ', (string) $user->name)[0])
            ->where('load', null)
            ->where('recentRuns', []));
});

// The route hero, zone bar and weather/location chips all went with PP3's
// featured-card cut and PS3's port to the prototype's mini last-run card, so
// the select carries only what Today still draws. A regression here is a
// per-request cost for nothing.
it('selects only the recent-run columns Today still draws', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 70, 'Z3' => 20]],
    ]);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentRuns.0.distance')
            ->has('recentRuns.0.trimp_edwards')
            ->missing('recentRuns.0.summary_polyline')
            ->missing('recentRuns.0.stream_summary')
            ->missing('recentRuns.0.location_name')
            ->missing('recentRuns.0.weather_temp_c'));
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
            ->component('Home')
            ->has('load.weekly_trimp')
            ->has('load.form')
            ->has('snapshot')
            ->has('recentRuns', 8));

    Carbon::setTestNow();
});

/**
 * `snapshot` is a single row — `TrainingLoadCard` takes one `WeeklySnapshot | null`.
 * The read used to pull the newest twelve and throw eleven away.
 */
it('reads only the newest weekly snapshot, not a window of them', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 14) as $weeksAgo) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($weeksAgo)->toDateString(),
        ]);
    }

    $newest = Carbon::today()->subWeek()->toDateString();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.week_ending', $newest));

    // Narrowed to the hydrating read; the briefing runs its own projection
    // (`select week_ending ... and runs > ?`) over the same table.
    $snapshotReads = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'select * from `weekly_snapshots`'),
    ));

    // toEndWith, not toContain: `limit 12` contains `limit 1`.
    expect($snapshotReads)->toHaveCount(1)
        ->and($snapshotReads[0])->toEndWith('limit 1');
});

it('does not ship the unused trendAnalysis or weeklyRecap props', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
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
 * Every briefing trigger on this page (`SuggestionCard`, `KataTemariCompact`)
 * polls `router.reload({ only: ['briefing'] })` every
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

    // `trimp_edwards` is unique to the recent-run select.
    $recentRunFetches = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'trimp_edwards'));
    $snapshotReads = array_filter($queries, fn (string $sql): bool => str_contains($sql, '`weekly_snapshots`'));

    expect($recentRunFetches)->toBeEmpty()
        ->and($snapshotReads)->toBeEmpty();

    $response->assertJsonPath('component', 'Home');
    // The one prop the poll does name still has to resolve.
    $response->assertJsonPath('props.briefing.mood', fn (mixed $mood): bool => is_string($mood));
    foreach (['load', 'snapshot', 'recentRuns', 'weekPlan'] as $skipped) {
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
            ->component('Home')
            ->has('briefing')
            ->has('snapshot')
            ->has('recentRuns', 1)
            ->has('pastYouTrend')
            ->has('weekPlan'));
});

it('ships weekPlan as null when the user has no planned sessions this week', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('weekPlan', null));
});

it('ships a real weekPlan when the user has a plan for the current week', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    for ($i = 0; $i < 7; $i++) {
        PlannedSession::factory()->for($user)->create(['date' => $weekStart->copy()->addDays($i)]);
    }

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('weekPlan.days', fn (mixed $days): bool => count($days) === 7)
            ->has('weekPlan.sessions_per_week')
            ->has('weekPlan.phase'));

    Carbon::setTestNow();
});

it('ships the Past You verdict as its own outcome when history is too thin', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('pastYouTrend.verdict', 'not_enough_history')
            ->where('pastYouTrend.comparison_count', 0)
            ->where('pastYouTrend.window_days', PastYouTrendBuilder::WINDOW_DAYS)
            ->etc());
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
        'X-Inertia-Partial-Component' => 'Home',
        'X-Inertia-Partial-Data' => 'briefing',
    ];
}
