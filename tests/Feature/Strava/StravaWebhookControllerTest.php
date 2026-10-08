<?php

declare(strict_types=1);

use App\Jobs\Strava\CleanupDeletedActivityJob;
use App\Jobs\Strava\ResyncActivityJob;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Jobs\Strava\VerifyStravaRevocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.strava.webhook_verify_token' => 'super-secret-token',
        'services.strava.webhook_callback_token' => 'fake-callback-token',
        'services.strava.webhook_subscription_id' => '424242',
    ]);
});

function stravaWebhookUrl(string $token = 'fake-callback-token'): string
{
    return route('strava.webhook.handle', ['token' => $token]);
}

it('rate-limits the public webhook POST route', function (): void {
    $route = Route::getRoutes()->getByName('strava.webhook.handle');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:60,1');
});

it('echoes the challenge back when the verify token matches', function (): void {
    $this->getJson(route('strava.webhook.verify', [
        'token' => 'fake-callback-token',
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'super-secret-token',
        'hub.challenge' => 'challenge-abc',
    ]))
        ->assertOk()
        ->assertExactJson(['hub.challenge' => 'challenge-abc']);
});

it('rejects the handshake when the verify token does not match', function (): void {
    $this->getJson(route('strava.webhook.verify', [
        'token' => 'fake-callback-token',
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'wrong-token',
        'hub.challenge' => 'challenge-abc',
    ]))->assertForbidden();
});

it('rejects the handshake when the mode is not subscribe', function (): void {
    $this->getJson(route('strava.webhook.verify', [
        'token' => 'fake-callback-token',
        'hub.mode' => 'unsubscribe',
        'hub.verify_token' => 'super-secret-token',
        'hub.challenge' => 'challenge-abc',
    ]))->assertForbidden();
});

it('answers the handshake only on the tokenised callback URL', function (string $url): void {
    $this->getJson($url.'?'.http_build_query([
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'super-secret-token',
        'hub.challenge' => 'challenge-abc',
    ]))->assertNotFound();
})->with([
    'no token' => '/strava/webhook',
    'wrong token' => '/strava/webhook/wrong-callback-token',
]);

it('dispatches nothing for a forged event', function (string $aspect, string $path, array $subscription): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    Activity::factory()->for($user)->create(['strava_external_id' => 9_001]);

    $this->postJson($path, $subscription + forgedStravaEvent($aspect))->assertNotFound();

    Bus::assertNothingDispatched();
})->with(['create', 'update'])->with([
    'no token' => ['/strava/webhook', ['subscription_id' => 424242]],
    'wrong token' => ['/strava/webhook/wrong-callback-token', ['subscription_id' => 424242]],
    'wrong subscription id' => ['/strava/webhook/fake-callback-token', ['subscription_id' => 1]],
    'no subscription id' => ['/strava/webhook/fake-callback-token', []],
]);

it('fails closed when no callback token is configured', function (): void {
    Bus::fake();
    config(['services.strava.webhook_callback_token' => null]);
    StravaConnection::factory()->for(User::factory())->create(['strava_athlete_id' => 42]);

    $this->postJson('/strava/webhook/x', ['subscription_id' => 424242] + forgedStravaEvent('create'))->assertNotFound();

    Bus::assertNothingDispatched();
});

/**
 * @return array<string, int|string>
 */
function forgedStravaEvent(string $aspect): array
{
    return [
        'object_type' => 'activity',
        'object_id' => 9_001,
        'aspect_type' => $aspect,
        'owner_id' => 42,
    ];
}

it('dispatches a scoped SyncActivitiesJob on an activity create event', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(stravaWebhookUrl(), [
        'object_type' => 'activity',
        'object_id' => 9_001,
        'aspect_type' => 'create',
        'owner_id' => 42,
        'subscription_id' => 424242,
        'event_time' => now()->timestamp,
    ])->assertOk();

    Bus::assertDispatched(fn (SyncActivitiesJob $job): bool => $job->userId === $user->id && $job->stravaActivityId === 9_001);
});

it('falls back to a full sync on an update for a run we have no local row for', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_002,
        'aspect_type' => 'update',
        'owner_id' => 42,
    ])->assertOk();

    Bus::assertDispatched(SyncActivitiesJob::class);
    Bus::assertNotDispatched(ResyncActivityJob::class);
});

it('re-ingests the existing local activity on an update event', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    $activity = Activity::factory()->for($user)->create(['strava_external_id' => 9_002]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_002,
        'aspect_type' => 'update',
        'owner_id' => 42,
    ])->assertOk();

    Bus::assertDispatched(fn (ResyncActivityJob $job): bool => $job->activityId === $activity->id);
    Bus::assertNotDispatched(SyncActivitiesJob::class);
});

it('does not dispatch when the athlete is unknown', function (): void {
    Bus::fake();

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_003,
        'aspect_type' => 'create',
        'owner_id' => 999,
    ])->assertOk();

    Bus::assertNotDispatched(SyncActivitiesJob::class);
});

it('acknowledges a webhook matching a demo connection without dispatching anything', function (): void {
    Bus::fake();
    $demo = User::factory()->demo()->create();
    $connection = StravaConnection::factory()->for($demo)->create();

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_003,
        'aspect_type' => 'create',
        'owner_id' => $connection->strava_athlete_id,
    ])->assertOk();

    Bus::assertNothingDispatched();
});

it('does not dispatch sync for a revoked connection', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create([
        'strava_athlete_id' => 42,
    ]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_004,
        'aspect_type' => 'create',
        'owner_id' => 42,
    ])->assertOk();

    Bus::assertNotDispatched(SyncActivitiesJob::class);
});

it('queues a cleanup job on an activity delete event', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_005,
        'aspect_type' => 'delete',
        'owner_id' => 42,
    ])->assertOk();

    Bus::assertDispatched(fn (CleanupDeletedActivityJob $job): bool => $job->userId === $user->id && $job->stravaActivityId === 9_005);
});

it('removes the local activity on an activity delete event', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    $activity = Activity::factory()->for($user)->create(['strava_external_id' => 9_005]);
    ActivityDetail::factory()->for($activity)->create();
    // The cleanup job verifies the deletion against Strava first (a 404).
    Http::fake(['strava.com/api/v3/activities/9005' => Http::response(['error' => 'Record Not Found'], 404)]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
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
    Http::fake(['strava.com/api/v3/activities/9006' => Http::response(['error' => 'Record Not Found'], 404)]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_006,
        'aspect_type' => 'delete',
        'owner_id' => 42,
    ])->assertOk();

    expect(Activity::query()->withStubs()->whereKey($stub->id)->exists())->toBeFalse();
});

it('queues a verification job on athlete deauthorization instead of revoking on the raw body', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'athlete',
        'object_id' => 42,
        'aspect_type' => 'update',
        'owner_id' => 42,
        'updates' => ['authorized' => 'false'],
    ])->assertOk();

    // The forgeable body must NOT revoke synchronously; a verification job does.
    expect($connection->fresh()->isRevoked())->toBeFalse();
    Bus::assertDispatched(fn (VerifyStravaRevocationJob $job): bool => $job->connectionId === $connection->id
        && $job->source === 'webhook_deauth'
        && $job->credentialVersion === $connection->credential_version);
});

it('revokes the connection when the verified deauthorization is genuine (Strava returns 401)', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    Http::fake(['strava.com/api/v3/athlete' => Http::response(['error' => 'Authorization Error'], 401)]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'athlete',
        'object_id' => 42,
        'aspect_type' => 'update',
        'owner_id' => 42,
        'updates' => ['authorized' => 'false'],
    ])->assertOk();

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('does NOT revoke on a forged deauthorization when the grant is still live', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    Http::fake(['strava.com/api/v3/athlete' => Http::response(['id' => 42], 200)]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'athlete',
        'object_id' => 42,
        'aspect_type' => 'update',
        'owner_id' => 42,
        'updates' => ['authorized' => 'false'],
    ])->assertOk();

    expect($connection->fresh()->isRevoked())->toBeFalse();
});

it('ignores an athlete update that is not a deauthorization', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);

    $this->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'athlete',
        'object_id' => 42,
        'aspect_type' => 'update',
        'owner_id' => 42,
        'updates' => ['authorized' => 'true'],
    ])->assertOk();

    expect($connection->fresh()->isRevoked())->toBeFalse();
});

function stubPulseEntry(): void
{
    $entry = Mockery::mock(Entry::class);
    $entry->shouldReceive('count')->andReturnSelf();
    Pulse::shouldReceive('record')->andReturn($entry);
}

function postWebhookAspect(string $aspect): void
{
    test()->postJson(stravaWebhookUrl(), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_001,
        'aspect_type' => $aspect,
        'owner_id' => 999,
    ])->assertOk();
}

it('records the heartbeat under the reported aspect when it is a known one', function (string $aspect): void {
    stubPulseEntry();

    postWebhookAspect($aspect);

    Pulse::shouldHaveReceived('record')->with('strava_webhook', $aspect)->once();
})->with(['create', 'update', 'delete']);

it('collapses an unrecognised aspect_type into a single heartbeat bucket', function (string $aspect): void {
    stubPulseEntry();

    postWebhookAspect($aspect);

    Pulse::shouldHaveReceived('record')->with('strava_webhook', 'unknown')->once();
})->with([
    'empty' => '',
    'unknown verb' => 'subscribe',
    'per-request cardinality bomb' => 'create-8f3b1c2d-9a4e',
    'injection-ish' => '<script>alert(1)</script>',
]);
