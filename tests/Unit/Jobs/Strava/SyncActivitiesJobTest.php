<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forwards to the SyncOrchestrator for the resolved user', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->withArgs(fn (User $arg): bool => $arg->is($user))
        ->andReturn(3);

    (new SyncActivitiesJob($user->id))->handle($orchestrator);
});

it('scopes to a single activity when a Strava activity id is given', function (): void {
    $user = User::factory()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncSingleActivity')
        ->once()
        ->withArgs(fn (User $arg, int $externalId): bool => $arg->is($user) && $externalId === 9_001)
        ->andReturn(true);
    $orchestrator->shouldNotReceive('syncUser');

    (new SyncActivitiesJob($user->id, 9_001))->handle($orchestrator);
});

it('revokes the connection when the token refresh permanently fails', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->andThrow(new StravaTokenRefreshFailedException('refresh rejected'));

    (new SyncActivitiesJob($user->id))->handle($orchestrator);

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('no-ops on a deleted user', function (): void {
    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldNotReceive('syncUser');

    (new SyncActivitiesJob(999_999))->handle($orchestrator);
});

it('retries with backoff so a transient Strava blip does not lose the sync', function (): void {
    $job = new SyncActivitiesJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120]);
});
