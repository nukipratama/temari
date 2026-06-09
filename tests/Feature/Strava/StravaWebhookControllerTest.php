<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.strava.webhook_verify_token' => 'super-secret-token']);
});

it('echoes the challenge back when the verify token matches', function (): void {
    $this->getJson(route('strava.webhook.verify', [
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'super-secret-token',
        'hub.challenge' => 'challenge-abc',
    ]))
        ->assertOk()
        ->assertExactJson(['hub.challenge' => 'challenge-abc']);
});

it('rejects the handshake when the verify token does not match', function (): void {
    $this->getJson(route('strava.webhook.verify', [
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'wrong-token',
        'hub.challenge' => 'challenge-abc',
    ]))->assertForbidden();
});

it('rejects the handshake when the mode is not subscribe', function (): void {
    $this->getJson(route('strava.webhook.verify', [
        'hub.mode' => 'unsubscribe',
        'hub.verify_token' => 'super-secret-token',
        'hub.challenge' => 'challenge-abc',
    ]))->assertForbidden();
});

it('dispatches a scoped SyncActivitiesJob on an activity create event', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'object_id' => 9_001,
        'aspect_type' => 'create',
        'owner_id' => 42,
        'subscription_id' => 1,
        'event_time' => now()->timestamp,
    ])->assertOk();

    Bus::assertDispatched(
        SyncActivitiesJob::class,
        fn (SyncActivitiesJob $job): bool => $job->userId === $user->id && $job->stravaActivityId === 9_001,
    );
});

it('dispatches a scoped SyncActivitiesJob on an activity update event', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'object_id' => 9_002,
        'aspect_type' => 'update',
        'owner_id' => 42,
    ])->assertOk();

    Bus::assertDispatched(SyncActivitiesJob::class);
});

it('does not dispatch when the athlete is unknown', function (): void {
    Bus::fake();

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'object_id' => 9_003,
        'aspect_type' => 'create',
        'owner_id' => 999,
    ])->assertOk();

    Bus::assertNotDispatched(SyncActivitiesJob::class);
});

it('does not dispatch sync for a revoked connection', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create([
        'strava_athlete_id' => 42,
    ]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'object_id' => 9_004,
        'aspect_type' => 'create',
        'owner_id' => 42,
    ])->assertOk();

    Bus::assertNotDispatched(SyncActivitiesJob::class);
});

it('removes the local activity on an activity delete event', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    $activity = Activity::factory()->for($user)->create(['strava_external_id' => 9_005]);
    ActivityDetail::factory()->for($activity)->create();

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'object_id' => 9_005,
        'aspect_type' => 'delete',
        'owner_id' => 42,
    ])->assertOk();

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeFalse()
        ->and(ActivityDetail::query()->where('activity_id', $activity->id)->exists())->toBeFalse();
});

it('removes a not-yet-ingested stub activity on a delete event', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    // A stub (analyzed_at null) is hidden by the AnalyzedScope; the delete must
    // still reach it via withStubs().
    $stub = Activity::factory()->for($user)->stub()->create(['strava_external_id' => 9_006]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'object_id' => 9_006,
        'aspect_type' => 'delete',
        'owner_id' => 42,
    ])->assertOk();

    expect(Activity::query()->withStubs()->whereKey($stub->id)->exists())->toBeFalse();
});

it('revokes the connection on athlete deauthorization', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'athlete',
        'object_id' => 42,
        'aspect_type' => 'update',
        'owner_id' => 42,
        'updates' => ['authorized' => 'false'],
    ])->assertOk();

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('ignores an athlete update that is not a deauthorization', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'athlete',
        'object_id' => 42,
        'aspect_type' => 'update',
        'owner_id' => 42,
        'updates' => ['authorized' => 'true'],
    ])->assertOk();

    expect($connection->fresh()->isRevoked())->toBeFalse();
});
