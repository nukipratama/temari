<?php

declare(strict_types=1);

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);


it('requires authentication for the show page', function (): void {
    $activity = Activity::factory()->create();

    $this->get("/activities/{$activity->id}")->assertRedirect('/login');
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
    $card = RunCard::factory()->for($activity)->create(['special_move' => 'Paru-paru Baja', 'rarity' => 'epic']);
    StoryLine::factory()->for($activity)->create([
        'user_id' => $user->id,
        'speech' => 'Run yang solid, paru-paru baja keluar.',
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('detail.name', 'Morning Run')
            ->where('storyLine.speech', 'Run yang solid, paru-paru baja keluar.')
            ->where('card.special_move', 'Paru-paru Baja')
            ->has('card.flavor_analysis')
            ->where('card.edition', ['index' => 1, 'total' => 1])
            ->where('card.public_share_url', route('activities.show', ['activity' => $card->activity_id])));
});

it('numbers the run card\'s edition within its rarity across the user\'s collection', function (): void {
    $user = User::factory()->create();
    foreach (['First', 'Second', 'Third'] as $move) {
        $act = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($act)->create();
        RunCard::factory()->for($act)->create(['rarity' => 'rare', 'special_move' => $move]);
    }
    $latest = Activity::query()->whereHas('runCard', fn ($q) => $q->where('special_move', 'Second'))->firstOrFail();

    $this->actingAs($user)->get("/activities/{$latest->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('card.edition', ['index' => 2, 'total' => 3]));
});

it('404s when trying to view another user\'s run', function (): void {
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $me = User::factory()->create();
    $this->actingAs($me)->get("/activities/{$activity->id}")->assertNotFound();
});

it('404s when the activity has not been analyzed yet', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertNotFound();
});

it('dispatches a ResolveActivityLocationJob when the run has coords but no resolved_at', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

/**
 * `useAnalysisTrigger` reloads this page every 3-15s for up to 30 ticks while
 * the run insights generate. The job's `ShouldBeUnique` lock only spans the
 * queued-or-running window, and a transient Nominatim miss deliberately leaves
 * `location_resolved_at` null, so each finished-but-unresolved attempt freed the
 * lock for the next tick to re-queue against a rate-limited public endpoint.
 *
 * `releaseUniqueJobLocks()` is what makes this honest: without it the fake holds
 * the unique lock forever and the test would pass with no guard at all.
 */
it('does not re-dispatch a ResolveActivityLocationJob on every poll tick', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();
        Queue::releaseUniqueJobLocks();
    }

    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

it('dispatches for a different run while another run holds the guard', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $activities = collect(range(1, 2))->map(function () use ($user): Activity {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_lat' => -6.24,
            'start_lng' => 106.81,
            'location_resolved_at' => null,
        ]);

        return $activity;
    });

    foreach ($activities as $activity) {
        $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();
        Queue::releaseUniqueJobLocks();
    }

    Queue::assertPushed(ResolveActivityLocationJob::class, 2);
});

it('does not dispatch a ResolveActivityLocationJob when already resolved', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => 'Jakarta',
        'location_resolved_at' => now(),
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

    Queue::assertNotPushed(ResolveActivityLocationJob::class);
});

it('does not dispatch when the run lacks coords', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => null,
        'start_lng' => null,
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

    Queue::assertNotPushed(ResolveActivityLocationJob::class);
});

/**
 * The analysis poller reloads `weeklySnapshots` every 3-15s while a recap is
 * generating. Every prop used to be computed in the method body, so each tick
 * re-ran the full run query, the note reader and the mood reader for a prop it
 * never asked for. Behind closures, Inertia skips them entirely.
 *
 * Note which of the two tests below actually pins that. Inertia strips
 * unrequested props from the response whether or not they were computed, so the
 * prop-surface test passes even with eager props and only documents the
 * contract. The query test is the one that fails if a prop is un-closured.
 */

/**
 * The story + run-insight props are the whole reload set of the detail page's
 * poll (`DEFAULT_RELOAD_PROPS` in resources/js/components/run/RunLenses.tsx),
 * and it ticks every 3-15s for up to 30 attempts while narration generates. Every
 * prop used to be computed in the method body, so each tick re-ran the past-you
 * match, the relative-effort baseline, the card payload and the story-line
 * query for props it never asked for. Behind closures, Inertia skips them.
 */
it('does not run the past-you match or the relative-effort baseline on an insight-only partial reload', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    RunCard::factory()->for($activity)->create();

    // Built before the mocks: the helper loads the real page to read the asset
    // version, which legitimately invokes both collaborators.
    $headers = insightOnlyHeaders($this->actingAs($user), $activity->id);

    $this->mock(PastYouMatcher::class, fn ($mock) => $mock->shouldNotReceive('findMatch'));

    $response = $this->actingAs($user)->get("/activities/{$activity->id}", $headers)->assertSuccessful();

    $response->assertJsonPath('component', 'Runs/Show');
    $response->assertJsonPath('props.speechAnalysis.type', AnalysisType::PostRunSpeech->value);
    $response->assertJsonPath('props.runInsight.type', AnalysisType::RunInsight->value);
    foreach (['pastYou', 'card', 'storyLine', 'moodFallback', 'isChainHead'] as $skipped) {
        $response->assertJsonMissingPath("props.{$skipped}");
    }
});

/**
 * Regression for the prod bug where Slice 3 (#559) consolidated
 * RunInsightTechnical/Splits/Zones into one RunInsight case without a cleanup
 * migration for the pre-consolidation rows: those retired-type rows survive
 * under their old string values, which the AnalysisType enum cast throws a
 * ValueError on the moment it is hydrated and read.
 */
it('renders the run detail page past orphaned pre-consolidation RunInsight rows', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);

    foreach (['run_insight_technical', 'run_insight_splits', 'run_insight_zones'] as $retiredType) {
        DB::table('ai_analyses')->insert([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $retiredType,
            'discriminator' => null,
            'status' => 'done',
            'content' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->actingAs($user)->get("/activities/{$activity->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('runInsight.type', AnalysisType::RunInsight->value)
            ->where('runInsight.status', 'pending'));
});

it('still resolves the card payload on the card-only partial reload', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    RunCard::factory()->for($activity)->create(['special_move' => 'Paru-paru Baja']);

    $headers = insightOnlyHeaders($this->actingAs($user), $activity->id);
    $headers['X-Inertia-Partial-Data'] = 'card';

    $response = $this->actingAs($user)->get("/activities/{$activity->id}", $headers)->assertSuccessful();

    $response->assertJsonPath('props.card.special_move', 'Paru-paru Baja');
    $response->assertJsonPath('props.card.edition', ['index' => 1, 'total' => 1]);
});

it('still runs the past-you match and the relative-effort baseline on a full run-detail load', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);

    $this->mock(PastYouMatcher::class, fn ($mock) => $mock->shouldReceive('findMatch')->once()->andReturn(null));

    $this->actingAs($user)->get("/activities/{$activity->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('pastYou', null));
});

it('runs no story-line queries when only the run insights are requested', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    RunCard::factory()->for($activity)->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN]);

    $headers = insightOnlyHeaders($this->actingAs($user), $activity->id);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();
    $fullLoad = $queries;

    $queries = [];
    $this->actingAs($user)->get("/activities/{$activity->id}", $headers)->assertSuccessful();
    $partialReload = $queries;

    // `storyLine` and `moodFallback` are the only readers of story_lines on this
    // page, and neither is in the poll's reload set.
    $storyLineReads = array_filter($partialReload, fn (string $sql): bool => str_contains($sql, '`story_lines`'));

    expect($storyLineReads)->toBeEmpty()
        ->and(count($partialReload))->toBeLessThan(count($fullLoad));
});


/**
 * Partial-reload headers mimicking the run-detail poller's
 * `router.reload({ only: [...the insight props] })`.
 *
 * @param  object  $actingAs  The authenticated test case.
 * @return array<string, string>
 */
function insightOnlyHeaders(object $actingAs, int $activityId): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersionFor($actingAs, "/activities/{$activityId}"),
        'X-Inertia-Partial-Component' => 'Runs/Show',
        'X-Inertia-Partial-Data' => 'speechAnalysis,runInsight',
    ];
}
