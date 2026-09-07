<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Enums\IngestState;
use App\Jobs\AI\KickoffRecapsJob;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

/**
 * @return list<array<string, mixed>>
 */
function stravaHistoryPage(int $firstId, int $count, string $firstDate): array
{
    return array_map(fn (int $offset): array => [
        'id' => $firstId - $offset,
        'sport_type' => 'Run',
        'name' => 'Run',
        'start_date' => CarbonImmutable::parse($firstDate)->subDays($offset)->toIso8601String(),
        'start_date_local' => CarbonImmutable::parse($firstDate)->subDays($offset)->toIso8601String(),
        'distance' => 8_000.0,
        'moving_time' => 2_700,
        'elapsed_time' => 2_800,
        'average_speed' => 2.96,
        'total_elevation_gain' => 40.0,
        'has_heartrate' => true,
        'average_heartrate' => 150.0,
        'max_heartrate' => 172,
    ], range(0, $count - 1));
}

it('backfills a whole athlete history for single-digit Strava reads', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => now()->addHours(2),
    ]);

    // 250 runs of history: a full 200-item page plus a short one.
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push(stravaHistoryPage(9_250, 200, '2026-05-10T06:00:00Z'))
            ->push(stravaHistoryPage(9_050, 50, '2025-10-22T06:00:00Z')),
        'strava.com/api/v3/activities/*' => Http::response(['detail' => 'must not be fetched'], 500),
    ]);

    $inserted = app(SyncOrchestrator::class)->syncUser($user);

    expect($inserted)->toBe(250);

    // The whole point of the slice: reads scale with pages, not with activities.
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v3/activities/'));
    expect((int) StravaSyncLog::query()->where('user_id', $user->id)->sum('api_calls_used'))->toBe(2);

    // Nothing queues a per-activity detail fetch off the back of a sync.
    Bus::assertNotDispatched(IngestActivityJob::class);
});

it('leaves the backfilled history visible, summary-only and honestly unscored', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => now()->addHours(2),
    ]);

    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::response(
            stravaHistoryPage(9_003, 3, '2026-05-10T06:00:00Z'),
        ),
    ]);

    app(SyncOrchestrator::class)->syncUser($user);

    expect(Activity::query()->where('user_id', $user->id)->count())->toBe(3)
        ->and(Activity::query()->summaryOnly()->where('user_id', $user->id)->count())->toBe(3)
        ->and(Activity::query()->detailed()->where('user_id', $user->id)->count())->toBe(0);

    $detail = ActivityDetail::query()->forUser($user->id)->firstOrFail();

    expect($detail->distance)->toBe(8_000.0)
        ->and($detail->average_heartrate)->toBe(150.0)
        ->and($detail->paceSecPerKm())->toBe(337.5)
        ->and($detail->trimp_edwards)->toBeNull()
        ->and($detail->stream_summary)->toBeNull();
});

it('renders the run-detail page for a summary-only run and queues its hydration', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['revoked_at' => null]);
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create([
        'stream_summary' => null,
        'trimp_edwards' => null,
        'splits_metric' => null,
    ]);

    $this->actingAs($user)
        ->get(route('activities.show', $activity))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Runs/Show')
            ->where('activity.ingest_state', IngestState::Summary->value)
            ->where('awaitingDetail', true)
            ->where('card', null));

    Bus::assertDispatched(IngestActivityJob::class, fn (IngestActivityJob $job): bool => $job->activityId === $activity->id);
});

it('does not claim a run is still filling in once it is detailed', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['revoked_at' => null]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create();

    $this->actingAs($user)
        ->get(route('activities.show', $activity))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('awaitingDetail', false));

    Bus::assertNotDispatched(IngestActivityJob::class);
});

it('does not promise a demo run will fill itself in, since nothing is coming for it', function (): void {
    Bus::fake();

    $user = User::factory()->create(['is_demo' => true]);
    StravaConnection::factory()->for($user)->create(['revoked_at' => null]);
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create([
        'stream_summary' => null,
        'trimp_edwards' => null,
        'splits_metric' => null,
    ]);

    $this->actingAs($user)
        ->get(route('activities.show', $activity))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('awaitingDetail', false));

    Bus::assertNotDispatched(IngestActivityJob::class);
});

it('renders the feed and calendar for a summary-only run without inventing a zero effort', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => now()->startOfMonth()->addDays(2),
        'stream_summary' => null,
        'trimp_edwards' => null,
    ]);

    $this->actingAs($user)->get(route('history'))->assertOk();

    $calendarUrl = route('history', ['view' => 'calendar']);
    $cells = $this->actingAs($user)
        ->get($calendarUrl, inertiaPartialHeaders($this->actingAs($user), $calendarUrl, 'History', 'cells'))
        ->assertOk()
        ->json('props.cells');

    $runCells = collect($cells)
        ->filter(fn (array $cell): bool => $cell['activity_id'] === $activity->id);

    expect($runCells)->toHaveCount(1)
        ->and($runCells->first()['distance_km'])->not->toBeNull()
        // Unknown load reads as unknown, never as a zero-effort day.
        ->and($runCells->first()['trimp'])->toBeNull();
});

it('lets the chained recap kickoff narrate the whole backfilled history without one extra Strava read', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    Bus::fake();

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => now()->addHours(2),
    ]);

    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::response(
            stravaHistoryPage(9_003, 3, '2026-05-10T06:00:00Z'),
        ),
    ]);

    app(SyncOrchestrator::class)->syncUser($user);
    Http::assertSentCount(1);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    new KickoffRecapsJob($user->id)->handle(
        app(KickoffWeeklyRecaps::class),
        app(KickoffMonthlyRecaps::class),
        app(PlanNarrationRequester::class),
        app(AnalysisService::class),
        app(Periodizer::class),
    );

    // The backfill already wrote the weekly snapshot and the summary detail rows
    // both kickoffs read, so nothing goes back to Strava.
    Http::assertSentCount(1);

    $weekly = collect($captured)->firstWhere('type', AnalysisType::WeeklyRecap);
    $monthly = collect($captured)->firstWhere('type', AnalysisType::MonthlyRecap);

    expect($weekly)->not->toBeNull()
        ->and($weekly['subjectOrType'])->toBe(WeeklySnapshot::class)
        ->and($weekly['invalidate'])->toBeFalse()
        ->and($monthly)->not->toBeNull()
        ->and($monthly['subjectId'])->toBe($user->id)
        ->and($monthly['discriminator'])->toBe('2026-05')
        ->and($monthly['invalidate'])->toBeFalse();

    Carbon::setTestNow();
});
