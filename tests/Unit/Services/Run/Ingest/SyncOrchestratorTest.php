<?php

declare(strict_types=1);

use Mockery\MockInterface;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\Analytics\StravaSyncLog;
use App\Models\WeeklySnapshot;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SummaryIngest;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Strava\ActivityFetcher;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\StravaClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->client = Mockery::mock(StravaClient::class);
    $this->client->shouldReceive('rateLimitRemaining')->andReturn(['15min' => 200, 'daily' => 2000]);
});

function orchestrator(ActivityFetcher|MockInterface $fetcher): SyncOrchestrator
{
    return new SyncOrchestrator(
        $fetcher,
        test()->client,
        app(SummaryIngest::class),
        app(WeeklyAggregator::class),
    );
}

/**
 * @param  list<int>  $ids
 * @return array{summaries: list<array<string, mixed>>, api_calls: int}
 */
function summaryResult(array $ids, int $apiCalls = 1): array
{
    return [
        'summaries' => array_map(fn (int $id): array => [
            'id' => $id,
            'sport_type' => 'Run',
            'name' => 'Run',
            'start_date_local' => '2026-05-10T06:00:00Z',
            'distance' => 5_000.0,
            'moving_time' => 1_800,
        ], $ids),
        'api_calls' => $apiCalls,
    ];
}

it('inserts oldest-first so DB ids are chronological', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andReturn(summaryResult([10, 20, 30]));

    $inserted = orchestrator($fetcher)->syncUser($user);

    expect($inserted)->toBe(3);
    $ids = Activity::query()->withStubs()->orderBy('id')->pluck('strava_external_id')->all();
    expect($ids)->toBe([10, 20, 30]);
});

it('stores visible summary-only runs without dispatching a per-activity detail fetch', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andReturn(summaryResult([10, 20]));

    $inserted = orchestrator($fetcher)->syncUser($user);

    expect($inserted)->toBe(2)
        // No withStubs(): the rows must already be past the AnalyzedScope.
        ->and(Activity::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Activity::query()->summaryOnly()->where('user_id', $user->id)->count())->toBe(2)
        ->and(ActivityDetail::query()->forUser($user->id)->pluck('distance')->all())->toBe([5_000.0, 5_000.0]);
    Queue::assertNotPushed(IngestActivityJob::class);
});

it('records the Strava reads a backfill actually spent', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andReturn(summaryResult(range(1, 250), apiCalls: 2));

    orchestrator($fetcher)->syncUser($user);

    // 250 runs of history for 2 reads — no per-activity detail call at sync time.
    expect((int) StravaSyncLog::query()->where('user_id', $user->id)->sum('api_calls_used'))->toBe(2)
        ->and(Activity::query()->where('user_id', $user->id)->count())->toBe(250);
});

it('rolls the weekly snapshots forward once for the whole backfill', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andReturn(summaryResult([10]));

    orchestrator($fetcher)->syncUser($user);

    $weekOfTheRun = WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', '2026-05-10')
        ->firstOrFail();

    // Volume is exact from the summary; load stays unscored, exactly as it
    // already does for an HR-less run.
    expect($weekOfTheRun->distance_km)->toBe(5.0)
        ->and($weekOfTheRun->runs)->toBe(1)
        ->and($weekOfTheRun->weekly_trimp)->toBe(0.0);
});

it('returns 0 and does not query Strava when user has no connection', function (): void {
    $user = User::factory()->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    $inserted = orchestrator($fetcher)->syncUser($user);

    expect($inserted)->toBe(0);
    Queue::assertNothingPushed();
});

it('logs an error and rethrows on an unexpected exception during syncUser', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andThrow(new RuntimeException('boom'));

    expect(fn () => orchestrator($fetcher)->syncUser($user))
        ->toThrow(RuntimeException::class, 'boom');

    expect(StravaSyncLog::query()->where('user_id', $user->id)->where('status', 'error')->value('error_message'))
        ->toBe('boom');
});

it('returns 0 when another sync holds the lock', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $lock = Cache::lock("strava-sync:user-{$user->id}", 60);
    $lock->get();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    try {
        $inserted = orchestrator($fetcher)->syncUser($user);
        expect($inserted)->toBe(0);
        Queue::assertNothingPushed();
    } finally {
        $lock->release();
    }
});

it('returns 0 when fetcher finds no new activities', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andReturn(summaryResult([]));

    expect(orchestrator($fetcher)->syncUser($user))->toBe(0);
    Queue::assertNothingPushed();
});

it('skips a revoked connection without querying Strava', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    expect(orchestrator($fetcher)->syncUser($user))->toBe(0);
    Queue::assertNothingPushed();
});

it('revokes the connection and returns 0 (no rethrow) when the API rejects the token with 401', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')
        ->andThrow(new StravaConnectionRevokedException('Strava rejected the access token with 401'));

    $inserted = orchestrator($fetcher)->syncUser($user);

    expect($inserted)->toBe(0)
        ->and($connection->fresh()->revoked_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('syncUser no-ops when the Strava kill-switch is off', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    expect(orchestrator($fetcher)->syncUser($user))->toBe(0);
    Queue::assertNothingPushed();
});

it('syncSingleActivity no-ops when the Strava kill-switch is off', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $fetcher = Mockery::mock(ActivityFetcher::class);

    expect(orchestrator($fetcher)->syncSingleActivity($user, 9_001))->toBeFalse();
    Queue::assertNothingPushed();
});

it('passes the --since lower bound through to the fetcher', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $since = CarbonImmutable::parse('2026-05-01');

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')
        ->once()
        ->withArgs(fn ($connection, $arg): bool => $arg instanceof CarbonImmutable && $arg->equalTo($since))
        ->andReturn(summaryResult([]));

    orchestrator($fetcher)->syncUser($user, $since);
});

it('inserts and queues exactly one IngestActivityJob for a single webhook activity', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    $result = orchestrator($fetcher)->syncSingleActivity($user, 9_001);

    expect($result)->toBeTrue()
        ->and(Activity::query()->withStubs()->where('user_id', $user->id)->where('strava_external_id', 9_001)->exists())->toBeTrue();
    Queue::assertPushed(IngestActivityJob::class, 1);
});

it('re-uses the existing row when a webhook update arrives for a known activity', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->stub()->create(['strava_external_id' => 9_002]);

    $fetcher = Mockery::mock(ActivityFetcher::class);

    orchestrator($fetcher)->syncSingleActivity($user, 9_002);

    expect(Activity::query()->withStubs()->where('user_id', $user->id)->where('strava_external_id', 9_002)->count())->toBe(1);
    Queue::assertPushed(IngestActivityJob::class, 1);
});

it('skips re-ingest on a duplicate webhook delivery for an already-analyzed activity', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->analyzed()->create(['strava_external_id' => 9_010]);

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    $result = orchestrator($fetcher)->syncSingleActivity($user, 9_010);

    expect($result)->toBeFalse()
        ->and(Activity::query()->where('user_id', $user->id)->where('strava_external_id', 9_010)->count())->toBe(1);
    Queue::assertNotPushed(IngestActivityJob::class);
});

it('still dispatches re-ingest for a stub (un-analyzed) activity on a webhook delivery', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->stub()->create(['strava_external_id' => 9_011]);

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewSummaries');

    $result = orchestrator($fetcher)->syncSingleActivity($user, 9_011);

    expect($result)->toBeTrue();
    Queue::assertPushed(IngestActivityJob::class, 1);
});

it('does not sync a single activity for a revoked connection', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);

    expect(orchestrator($fetcher)->syncSingleActivity($user, 9_003))->toBeFalse();
    Queue::assertNothingPushed();
});
