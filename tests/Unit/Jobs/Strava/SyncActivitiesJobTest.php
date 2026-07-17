<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\Activity;
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
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->withArgs(fn (User $arg): bool => $arg->is($user))
        ->andReturn(3);

    new SyncActivitiesJob($user->id)->handle($orchestrator);
});

it('scopes to a single activity when a Strava activity id is given', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncSingleActivity')
        ->once()
        ->withArgs(fn (User $arg, int $externalId): bool => $arg->is($user) && $externalId === 9_001)
        ->andReturn(true);
    $orchestrator->shouldNotReceive('syncUser');

    new SyncActivitiesJob($user->id, 9_001)->handle($orchestrator);
});

it('revokes the connection and purges stubs when the token refresh permanently fails (400)', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();
    $stub = Activity::factory()->stub()->for($user)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
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
    $orchestrator->shouldReceive('syncUser')
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
    $orchestrator->shouldReceive('syncUser')
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
    $orchestrator->shouldReceive('syncUser')
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
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->andThrow(new StravaConnectionRevokedException('401 unauthorized'));

    new SyncActivitiesJob($user->id)->handle($orchestrator);

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('no-ops on a deleted user', function (): void {
    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldNotReceive('syncUser');

    new SyncActivitiesJob(999_999)->handle($orchestrator);
});

it('retries with backoff so a transient Strava blip does not lose the sync', function (): void {
    $job = new SyncActivitiesJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120]);
});
