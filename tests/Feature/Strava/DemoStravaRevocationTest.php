<?php

declare(strict_types=1);

use App\Jobs\Strava\ResyncActivityJob;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\StravaGrantEvent;
use App\Models\User;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.strava.webhook_callback_token' => 'fake-callback-token',
        'services.strava.webhook_subscription_id' => '424242',
    ]);
    Http::fake(['*' => Http::response(['message' => 'Authorization Error'], 401)]);

    $pulseEntry = Mockery::mock(Entry::class);
    $pulseEntry->shouldReceive('count')->andReturnSelf();
    $pulseEntry->shouldReceive('sum')->andReturnSelf();
    Pulse::shouldReceive('record')->andReturn($pulseEntry)->zeroOrMoreTimes();

    $this->demo = User::factory()->demo()->create();
    $this->connection = StravaConnection::factory()->for($this->demo)->create(['strava_athlete_id' => 42]);
});

function expectDemoConnectionUntouched(StravaConnection $connection): void
{
    expect($connection->fresh()->revoked_at)->toBeNull()
        ->and(DatabaseNotification::query()->count())->toBe(0)
        ->and(StravaGrantEvent::query()->count())->toBe(0);
    Pulse::shouldNotHaveReceived('record', ['strava_revoked', Mockery::any()]);
    Http::assertNothingSent();
}

it('refuses a demo Sync now without touching the connection', function (): void {
    $this->actingAs($this->demo)->post(route('strava.sync'))->assertForbidden();

    expectDemoConnectionUntouched($this->connection);
});

it('refuses a demo zones resync without touching the connection', function (): void {
    $this->actingAs($this->demo)->post(route('settings.zones.resync'))->assertForbidden();

    expectDemoConnectionUntouched($this->connection);
});

it('acks a webhook delivery for the demo athlete without touching the connection', function (string $aspect): void {
    Activity::factory()->for($this->demo)->create(['strava_external_id' => 9_001]);

    $this->postJson(route('strava.webhook.handle', ['token' => 'fake-callback-token']), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_001,
        'aspect_type' => $aspect,
        'owner_id' => 42,
    ])->assertOk();

    expectDemoConnectionUntouched($this->connection);
})->with(['create', 'update']);

it('skips every sync and ingest path for the demo user', function (): void {
    $stub = Activity::factory()->stub()->for($this->demo)->create(['strava_external_id' => 9_002]);

    SyncActivitiesJob::dispatchSync($this->demo->id);
    SyncActivitiesJob::dispatchSync($this->demo->id, 9_003);
    ResyncActivityJob::dispatchSync($stub->id);
    app(ActivityPipeline::class)->ingest($stub->fresh());

    expectDemoConnectionUntouched($this->connection);
    expect(Activity::withStubs()->where('user_id', $this->demo->id)->count())->toBe(1);
});
