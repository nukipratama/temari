<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('syncs all users with a Strava connection', function (): void {
    $userA = User::factory()->create();
    StravaConnection::factory()->for($userA)->create();
    $userB = User::factory()->create();
    StravaConnection::factory()->for($userB)->create();
    User::factory()->create(); // no connection — should be skipped

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')->twice()->andReturn(0);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync')->assertSuccessful();
});

it('honors the --user filter', function (): void {
    $userA = User::factory()->create();
    StravaConnection::factory()->for($userA)->create();
    $userB = User::factory()->create();
    StravaConnection::factory()->for($userB)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->withArgs(fn (User $arg): bool => $arg->is($userA))
        ->andReturn(2);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync', ['--user' => $userA->id])
        ->expectsOutputToContain("user {$userA->id}: 2 new activities queued")
        ->assertSuccessful();
});

it('warns when no users with Strava connection exist', function (): void {
    $this->artisan('strava:sync')
        ->expectsOutputToContain('No users with a Strava connection found.')
        ->assertSuccessful();
});
