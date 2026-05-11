<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
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

it('no-ops on a deleted user', function (): void {
    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldNotReceive('syncUser');

    (new SyncActivitiesJob(999_999))->handle($orchestrator);
});
