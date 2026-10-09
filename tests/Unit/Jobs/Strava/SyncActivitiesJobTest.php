<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Services\Strava\StravaClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Activity;
use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

uses(RefreshDatabase::class);

/**
 * Minimal stand-in for the underlying queue job so we can assert release() was
 * called with a delay without booting a real queue connection. Mocks the Job
 * contract so it satisfies SyncActivitiesJob::setJob()'s type hint; the captured
 * delay is exposed on the `releasedWith` property.
 */
function fakeQueueJob(): Job
{
    $job = Mockery::mock(Job::class);
    $job->releasedWith = null;
    $job->shouldReceive('release')->andReturnUsing(function (int $delay = 0) use ($job): void {
        $job->releasedWith = $delay;
    });
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldIgnoreMissing();

    return $job;
}

it('forwards to the SyncOrchestrator for the resolved user', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')
        ->once()
        ->withArgs(fn (User $arg, int $maxPages, ?int $before): bool => $arg->is($user)
            && $maxPages === SyncActivitiesJob::PAGES_PER_ATTEMPT
            && $before === null)
        ->andReturn(null);

    new SyncActivitiesJob($user->id)->handle($orchestrator);
});

it('scopes to a single activity when a Strava activity id is given', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncSingleActivity')
        ->once()
        ->withArgs(fn (User $arg, int $externalId): bool => $arg->is($user) && $externalId === 9_001)
        ->andReturn(true);
    $orchestrator->shouldNotReceive('syncUserPages');

    new SyncActivitiesJob($user->id, 9_001)->handle($orchestrator);
});

it('revokes the connection and purges stubs when the token refresh permanently fails (400)', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();
    $stub = Activity::factory()->stub()->for($user)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')
        ->once()
        ->andThrow(new StravaTokenRefreshFailedException('refresh rejected'));

    new SyncActivitiesJob($user->id)->handle($orchestrator);

    expect($connection->fresh()->isRevoked())->toBeTrue()
        ->and(Activity::withStubs()->whereKey($stub->id)->exists())->toBeFalse();
});

it('releases with backoff instead of revoking on a transient refresh failure', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();
    $stub = Activity::factory()->stub()->for($user)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')
        ->once()
        ->andThrow(new StravaTokenRefreshTransientException('Strava 503'));

    $job = new SyncActivitiesJob($user->id);
    $queueJob = fakeQueueJob();
    $job->setJob($queueJob);

    $job->handle($orchestrator);

    // Connection stays healthy and its un-ingested stub is preserved; the job is
    // released so a later attempt recovers the sync rather than destroying it.
    expect($connection->fresh()->isRevoked())->toBeFalse()
        ->and(Activity::withStubs()->whereKey($stub->id)->exists())->toBeTrue()
        ->and($queueJob->releasedWith)->toBe(60);
});

it('releases with a 60s backoff on a rate-limit exception', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')
        ->once()
        ->andThrow(new StravaRateLimitedException('rate limited', availableIn: 120));

    $job = new SyncActivitiesJob($user->id);
    $queueJob = fakeQueueJob();
    $job->setJob($queueJob);

    $job->handle($orchestrator);

    expect($queueJob->releasedWith)->toBe(60);
});

it('drops the run without releasing when the circuit breaker is open', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')
        ->once()
        ->andThrow(new StravaCircuitOpenException('breaker open'));

    $job = new SyncActivitiesJob($user->id);
    $queueJob = fakeQueueJob();
    $job->setJob($queueJob);

    $job->handle($orchestrator);

    // No retry scheduled — the hourly scheduled sync recovers once the breaker
    // half-opens, so this run is simply dropped rather than released.
    expect($queueJob->releasedWith)->toBeNull();
});

it('revokes the connection when the API rejects the token with a 401', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')
        ->once()
        ->andThrow(new StravaConnectionRevokedException('401 unauthorized'));

    new SyncActivitiesJob($user->id)->handle($orchestrator);

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('ignores a stale API 401 after credentials change during the sync', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['credential_version' => 2]);

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')->once()->andReturnUsing(function () use ($connection): never {
        $connection->update(['credential_version' => 3, 'revoked_at' => null]);

        throw new StravaConnectionRevokedException('401 unauthorized');
    });
    Pulse::shouldReceive('record')->never();

    new SyncActivitiesJob($user->id)->handle($orchestrator);

    expect($connection->fresh()->isRevoked())->toBeFalse();
});

it('ignores a stale refresh failure after credentials change during the sync', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['credential_version' => 2]);

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUserPages')->once()->andReturnUsing(function () use ($connection): never {
        $connection->update(['credential_version' => 3, 'revoked_at' => null]);

        throw new StravaTokenRefreshFailedException('refresh rejected');
    });
    Pulse::shouldReceive('record')->never();

    new SyncActivitiesJob($user->id)->handle($orchestrator);

    expect($connection->fresh()->isRevoked())->toBeFalse();
});

it('no-ops on a deleted user', function (): void {
    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldNotReceive('syncUserPages');

    new SyncActivitiesJob(999_999)->handle($orchestrator);
});

it('logs and closes the sync log with a terminal status once $tries is exhausted', function (): void {
    Log::spy();
    $user = User::factory()->create();

    new SyncActivitiesJob($user->id)->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'strava.sync.failed'
            && $context['user_id'] === $user->id
            && $context['reason'] === 'boom',
    );

    $log = StravaSyncLog::query()->where('user_id', $user->id)->latest('id')->first();

    expect($log->status)->toBe('failed')
        ->and($log->error_message)->toBe('boom');
});

it('records the failure against the given user even when nothing was ever resolved', function (): void {
    Log::spy();

    new SyncActivitiesJob(999_999)->failed(new RuntimeException('database hiccup'));

    expect(StravaSyncLog::query()->where('user_id', 999_999)->where('status', 'failed')->exists())
        ->toBeTrue();
});

/**
 * Fake Strava's `/athlete/activities` over a history of `$runs` runs, honouring
 * `before`, `page` and `per_page`, and advancing the clock by the per-call HTTP
 * ceiling on every read so each page costs a worst-case slow call.
 *
 * @param  array<int, int>  $statusByRead  1-based read number => forced HTTP status
 * @param  array<int, int>  $startOffsetByIndex  history index => start offset in hours, overriding the 12h spacing
 * @return list<array<string, mixed>>  the history, newest-first
 */
function fakeSlowStravaHistory(int $runs, array $statusByRead = [], array $startOffsetByIndex = []): array
{
    $newest = CarbonImmutable::parse('2026-08-01T06:00:00Z');
    $history = array_map(fn (int $offset): array => [
        'id' => 500_000 - $offset,
        'sport_type' => 'Run',
        'name' => 'Run',
        'start_date' => $newest->subHours($startOffsetByIndex[$offset] ?? $offset * 12)->toIso8601String(),
        'start_date_local' => $newest->subHours($startOffsetByIndex[$offset] ?? $offset * 12)->toIso8601String(),
        'distance' => 8_000.0,
        'moving_time' => 2_700,
        'elapsed_time' => 2_800,
        'average_speed' => 2.96,
    ], range(0, $runs - 1));

    $reads = 0;
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => function (Request $request) use ($history, $statusByRead, &$reads) {
            $reads++;
            Carbon::setTestNow(now()->addSeconds(StravaClient::HTTP_TIMEOUT_SECONDS));
            if (isset($statusByRead[$reads])) {
                return Http::response([], $statusByRead[$reads]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $before = isset($query['before']) ? (int) $query['before'] : null;
            $perPage = (int) $query['per_page'];
            $page = (int) $query['page'];

            $visible = array_values(array_filter(
                $history,
                fn (array $item): bool => $before === null || CarbonImmutable::parse($item['start_date'])->getTimestamp() < $before,
            ));

            return Http::response(array_slice($visible, ($page - 1) * $perPage, $perPage));
        },
    ]);

    return $history;
}

function activityListReads(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/athlete/activities'))->count();
}

function connectedStravaUser(): User
{
    RateLimiter::clear(StravaClient::rateLimitKey('15min'));
    RateLimiter::clear(StravaClient::rateLimitKey('daily'));
    Carbon::setTestNow('2026-08-02T06:00:00Z');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => now()->addHours(2),
    ]);

    return $user;
}

class RecordsBackfillTailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(): void
    {
        Cache::put('backfill-tail-saw', Activity::withStubs()->where('user_id', $this->userId)->count());
    }
}

it('walks at most PAGES_PER_ATTEMPT slow pages per attempt, chains a continuation from the last cursor, and keeps walking past stored runs on a resume above them', function (): void {
    $user = connectedStravaUser();
    $history = fakeSlowStravaHistory(SyncActivitiesJob::PAGES_PER_ATTEMPT * 200 + 250);
    $startedAt = now();

    $job = new SyncActivitiesJob($user->id);
    $job->handle(app(SyncOrchestrator::class));

    expect(activityListReads())->toBe(SyncActivitiesJob::PAGES_PER_ATTEMPT)
        ->and((int) $startedAt->diffInSeconds(now()))->toBeLessThan(60)
        ->and(Activity::withStubs()->where('user_id', $user->id)->count())->toBe(SyncActivitiesJob::PAGES_PER_ATTEMPT * 200)
        ->and($job->chained)->toHaveCount(1);

    $next = unserialize($job->chained[0]);
    $lastWalked = $history[SyncActivitiesJob::PAGES_PER_ATTEMPT * 200 - 1];

    expect($next)->toBeInstanceOf(SyncActivitiesJob::class)
        ->and($next->userId)->toBe($user->id)
        ->and($next->stravaActivityId)->toBeNull()
        ->and($next->before)->toBe(CarbonImmutable::parse($lastWalked['start_date'])->getTimestamp() + 1);

    $retry = new SyncActivitiesJob($user->id, before: CarbonImmutable::parse($history[199]['start_date'])->getTimestamp());
    $retry->handle(app(SyncOrchestrator::class));

    expect(Activity::withStubs()->where('user_id', $user->id)->count())->toBe(600)
        ->and($retry->chained)->toHaveCount(1);
});

it('chains a continuation that finds the per-user lock held again from the same cursor, after a delay', function (): void {
    $user = connectedStravaUser();
    fakeSlowStravaHistory(SyncActivitiesJob::PAGES_PER_ATTEMPT * 200 + 250);
    Cache::lock("strava-sync:user-{$user->id}", 600)->get();

    $job = new SyncActivitiesJob($user->id, before: 1_780_000_000);
    $job->handle(app(SyncOrchestrator::class));

    $next = unserialize($job->chained[0]);

    expect(activityListReads())->toBe(0)
        ->and($job->chained)->toHaveCount(1)
        ->and($next->before)->toBe(1_780_000_000)
        ->and($next->delay)->toBe(SyncActivitiesJob::LOCK_RETRY_SECONDS);
});

it('stores both runs that start in the same second across a page boundary', function (): void {
    $user = connectedStravaUser();
    $boundary = SyncActivitiesJob::PAGES_PER_ATTEMPT * 200;
    $runs = $boundary + 1;
    fakeSlowStravaHistory($runs, startOffsetByIndex: [$boundary => ($boundary - 1) * 12]);

    Bus::chain([new SyncActivitiesJob($user->id)])->dispatch();

    expect(Activity::withStubs()->where('user_id', $user->id)->count())->toBe($runs);
});

it('completes a long backfill across chained attempts, ingesting every run exactly once before the chain moves on', function (): void {
    $user = connectedStravaUser();
    $runs = SyncActivitiesJob::PAGES_PER_ATTEMPT * 200 * 2 + 1;
    fakeSlowStravaHistory($runs);

    Bus::chain([
        new SyncActivitiesJob($user->id),
        new RecordsBackfillTailJob($user->id),
    ])->dispatch();

    $ids = Activity::withStubs()->where('user_id', $user->id)->pluck('strava_external_id');

    expect($ids)->toHaveCount($runs)
        ->and($ids->unique())->toHaveCount($runs)
        ->and(StravaSyncLog::query()->where('user_id', $user->id)->count())->toBe(3)
        ->and(activityListReads())->toBe(SyncActivitiesJob::PAGES_PER_ATTEMPT * 2 + 1)
        ->and(Cache::get('backfill-tail-saw'))->toBe((string) $runs);
});

it('chains nothing when the walk finishes under the page cap', function (): void {
    $user = connectedStravaUser();
    fakeSlowStravaHistory(150);

    $job = new SyncActivitiesJob($user->id);
    $job->handle(app(SyncOrchestrator::class));

    expect(activityListReads())->toBe(1)
        ->and(Activity::withStubs()->where('user_id', $user->id)->count())->toBe(150)
        ->and($job->chained)->toBe([]);
});

it('releases a rate-limited continuation without chaining or storing, so the retry resumes from the same cursor', function (): void {
    $user = connectedStravaUser();
    fakeSlowStravaHistory(SyncActivitiesJob::PAGES_PER_ATTEMPT * 200 + 250, statusByRead: [2 => 429]);

    $job = new SyncActivitiesJob($user->id, before: 1_780_000_000);
    $queueJob = fakeQueueJob();
    $job->setJob($queueJob);

    $job->handle(app(SyncOrchestrator::class));

    expect($queueJob->releasedWith)->toBe(60)
        ->and($job->chained)->toBe([])
        ->and($job->before)->toBe(1_780_000_000)
        ->and(Activity::withStubs()->where('user_id', $user->id)->count())->toBe(0);
});
