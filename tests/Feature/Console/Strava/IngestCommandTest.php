<?php

declare(strict_types=1);

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('no-ops when the Strava kill-switch is off', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->stub()->count(3)->create();
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $this->artisan('strava:ingest')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('dispatches an IngestActivityJob for each pending stub', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->stub()->count(3)->create();

    $this->artisan('strava:ingest')->assertSuccessful();

    Queue::assertPushed(IngestActivityJob::class, 3);
});

it('drains at most --batch activities per run', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->stub()->count(8)->create();

    $this->artisan('strava:ingest', ['--batch' => 5])->assertSuccessful();

    Queue::assertPushed(IngestActivityJob::class, 5);
});

it('dispatches oldest-first by activity id', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $oldest = Activity::factory()->for($user)->stub()->create();
    $middle = Activity::factory()->for($user)->stub()->create();
    Activity::factory()->for($user)->stub()->create();

    $this->artisan('strava:ingest', ['--batch' => 2])->assertSuccessful();

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $oldest->id,
    );
    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $middle->id,
    );
    Queue::assertPushed(IngestActivityJob::class, 2);
});

it('skips an activity that is not pending ingest and queues the one that is', function (Closure $makeSkipped): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $makeSkipped($user);
    $pending = Activity::factory()->for($user)->stub()->create(['detail_fail_count' => 4]);

    $this->artisan('strava:ingest')->assertSuccessful();

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $pending->id,
    );
    Queue::assertPushed(IngestActivityJob::class, 1);
})->with([
    'already analyzed' => [fn (User $user) => Activity::factory()->for($user)->analyzed()->create()],
    'detail_fail_count has reached the cap' => [fn (User $user) => Activity::factory()->for($user)->stub()->create(['detail_fail_count' => 5])],
]);

it('skips activities belonging to the demo user', function (): void {
    $demo = User::factory()->demo()->create();
    StravaConnection::factory()->for($demo)->create();
    Activity::factory()->for($demo)->stub()->create();

    $this->artisan('strava:ingest')->assertSuccessful();

    Queue::assertNotPushed(IngestActivityJob::class);
});

it('skips the activities of a user without a usable Strava connection', function (bool $revoked): void {
    $user = User::factory()->create();
    if ($revoked) {
        StravaConnection::factory()->for($user)->revoked()->create();
    }
    Activity::factory()->for($user)->stub()->create();

    $this->artisan('strava:ingest')->assertSuccessful();

    Queue::assertNotPushed(IngestActivityJob::class);
})->with([
    'connection is revoked' => [true],
    'no connection' => [false],
]);

it('reports when there are no pending activities to ingest', function (): void {
    $this->artisan('strava:ingest')
        ->expectsOutputToContain('No pending activities to ingest.')
        ->assertSuccessful();

    Queue::assertNotPushed(IngestActivityJob::class);
});
