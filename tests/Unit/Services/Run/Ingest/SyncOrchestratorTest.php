<?php

declare(strict_types=1);

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Strava\ActivityFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('inserts oldest-first so DB ids are chronological', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewExternalIds')->andReturn([10, 20, 30]);

    $inserted = (new SyncOrchestrator($fetcher))->syncUser($user);

    expect($inserted)->toBe(3);
    $ids = Activity::query()->orderBy('id')->pluck('strava_external_id')->all();
    expect($ids)->toBe([10, 20, 30]);
});

it('dispatches one IngestActivityJob per new activity', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewExternalIds')->andReturn([10, 20]);

    (new SyncOrchestrator($fetcher))->syncUser($user);

    Queue::assertPushed(IngestActivityJob::class, 2);
});

it('returns 0 and does not query Strava when user has no connection', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewExternalIds');

    $inserted = (new SyncOrchestrator($fetcher))->syncUser($user);

    expect($inserted)->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 0 when another sync holds the lock', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $lock = Cache::lock("strava-sync:user-{$user->id}", 60);
    $lock->get();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldNotReceive('fetchNewExternalIds');

    try {
        $inserted = (new SyncOrchestrator($fetcher))->syncUser($user);
        expect($inserted)->toBe(0);
        Queue::assertNothingPushed();
    } finally {
        $lock->release();
    }
});

it('returns 0 when fetcher finds no new activities', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewExternalIds')->andReturn([]);

    expect((new SyncOrchestrator($fetcher))->syncUser($user))->toBe(0);
    Queue::assertNothingPushed();
});
