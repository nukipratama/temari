<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\PastYouTrendBuilder;
use App\Models\WeeklySnapshot;
use Illuminate\Database\Events\QueryExecuted;
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
            ->missing('load')
            ->where('hasRuns', false));
});

// Home reads this prop for one thing: whether the empty state is drawn. It used
// to ship an eight-row, eight-column select of rows nothing rendered.
it('answers the empty state with a boolean, hydrating no run rows', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('hasRuns', true));

    $existsProbes = array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'select exists') && str_contains($sql, '`activity_details`'),
    );
    $eightRowSelects = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'limit 8'));

    expect($existsProbes)->toHaveCount(1)
        ->and($eightRowSelects)->toBeEmpty();
});

it('renders the week snapshot and flags the runs when the user has training-load history', function (): void {
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
            ->missing('load')
            ->has('snapshot')
            ->where('hasRuns', true));

    Carbon::setTestNow();
});

/**
 * `snapshot` is a single row — `TrainingLoadCard` takes one `WeeklySnapshot | null`.
 * The read used to pull the newest twelve and throw eleven away.
 */
it('reads the weekly snapshots once and shows the newest', function (): void {
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

    expect($snapshotReads)->toHaveCount(1);
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

    $recentRunFetches = array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'select exists') && str_contains($sql, '`activity_details`'),
    );
    $snapshotReads = array_filter($queries, fn (string $sql): bool => str_contains($sql, '`weekly_snapshots`'));

    expect($recentRunFetches)->toBeEmpty()
        ->and($snapshotReads)->toBeEmpty();

    $response->assertJsonPath('component', 'Home');
    // The one prop the poll does name still has to resolve.
    $response->assertJsonPath('props.briefing.mood', fn (mixed $mood): bool => is_string($mood));
    foreach (['snapshot', 'hasRuns', 'weekPlan'] as $skipped) {
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
            ->where('hasRuns', true)
            ->has('pastYouTrend')
            ->has('weekPlan')
            ->missing('load'));
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
            ->has('weekPlan.sessions_this_week')
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

// Home reaches the same collaborators from six independent prop closures, so
// the count is a budget rather than an exact figure: it is allowed to move with
// the page, but a memoization regression (Vibe or the active race resolving per
// caller again) shows up here as several statements at once.
//
// The budget is the steady-state request the athlete almost always makes, so
// the caches are warmed by a first pass that is not counted. Scoped resolvers
// must be forgotten between the two the way a real second request forgets them,
// or the memos carry over and the count reads lower than any request ever is.
// The week is pinned, so the readiness clamp stays out of it; the clamped day
// is budgeted by the test below.
it('paints Home inside its query budget', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['completed_at' => null]);
    foreach (range(1, 6) as $daysAgo) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->subDays($daysAgo),
            'distance' => 8000.0,
            'trimp_edwards' => 70.0,
        ]);
    }
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->toDateString(),
    ]);
    foreach (range(0, 6) as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->startOfWeek(Carbon::MONDAY)->addDays($offset)->toDateString(),
            'pinned' => true,
        ]);
    }

    $this->actingAs($user)->get('/')->assertSuccessful();
    $this->app->forgetScopedInstances();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get('/')->assertSuccessful();

    expect($queries)->toBeLessThanOrEqual(15);
});

// clampVoiceFor used to be an argument inside the per-day `->map()`, so a day
// the readiness ceiling stepped down cost seven identical reads of the same
// single value.
it('reads the clamp narration once on a clamped day', function (): void {
    Carbon::setTestNow('2026-09-10 09:00:00');
    $user = User::factory()->create();

    // A run already in the bag caps readiness at easy-only, which an interval
    // session is above.
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->setHour(6)]);

    foreach (range(0, 6) as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->startOfWeek(Carbon::MONDAY)->addDays($offset)->toDateString(),
            'session_type' => SessionType::Interval,
            'pinned' => false,
        ]);
    }

    $clampReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$clampReads): void {
        if (str_contains($query->sql, 'select `content` from `ai_analyses`')
            && in_array(AnalysisType::PlanClampVoice->value, $query->bindings, true)) {
            $clampReads++;
        }
    });

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('weekPlan.days', 7));

    expect($clampReads)->toBe(1);

    Carbon::setTestNow();
});

it('tells Home this is the athlete\'s first briefing, until one has been narrated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('briefing.firstRead', true)->etc());

    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'status' => AnalysisStatus::Done,
        'content' => 'read.',
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('briefing.firstRead', false)->etc());
});
