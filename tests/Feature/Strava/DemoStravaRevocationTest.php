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
use Mockery\MockInterface;

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
});

/** @return array{demo: User, connection: StravaConnection} */
function demoStravaConnection(): array
{
    $demo = User::factory()->demo()->create();

    return [
        'demo' => $demo,
        'connection' => StravaConnection::factory()->for($demo)->create(['strava_athlete_id' => 42]),
    ];
}

function expectDemoConnectionUntouched(StravaConnection $connection): void
{
    expect($connection->refresh()->revoked_at)->toBeNull()
        ->and(DatabaseNotification::query()->count())->toBe(0)
        ->and(StravaGrantEvent::query()->count())->toBe(0);
    /** @var MockInterface $pulseMock */
    $pulseMock = Pulse::getFacadeRoot();
    $pulseMock->shouldNotHaveReceived('record', ['strava_revoked', Mockery::any()]);
    Http::assertNothingSent();
}

it('refuses a demo Sync now without touching the connection', function (): void {
    ['demo' => $demo, 'connection' => $connection] = demoStravaConnection();
    $this->actingAs($demo)->post(route('strava.sync'))->assertForbidden();

    expectDemoConnectionUntouched($connection);
});

it('refuses a demo zones resync without touching the connection', function (): void {
    ['demo' => $demo, 'connection' => $connection] = demoStravaConnection();
    $this->actingAs($demo)->post(route('settings.zones.resync'))->assertForbidden();

    expectDemoConnectionUntouched($connection);
});

it('acks a webhook delivery for the demo athlete without touching the connection', function (string $aspect): void {
    ['demo' => $demo, 'connection' => $connection] = demoStravaConnection();
    Activity::factory()->for($demo)->create(['strava_external_id' => 9_001]);

    $this->postJson(route('strava.webhook.handle', ['token' => 'fake-callback-token']), [
        'subscription_id' => 424242,
        'object_type' => 'activity',
        'object_id' => 9_001,
        'aspect_type' => $aspect,
        'owner_id' => 42,
    ])->assertOk();

    expectDemoConnectionUntouched($connection);
})->with(['create', 'update']);

it('skips every sync and ingest path for the demo user', function (): void {
    ['demo' => $demo, 'connection' => $connection] = demoStravaConnection();
    $stub = Activity::factory()->stub()->for($demo)->create(['strava_external_id' => 9_002]);

    SyncActivitiesJob::dispatchSync($demo->id);
    SyncActivitiesJob::dispatchSync($demo->id, 9_003);
    ResyncActivityJob::dispatchSync($stub->id);
    app(ActivityPipeline::class)->ingest($stub->refresh());

    expectDemoConnectionUntouched($connection);
    expect(Activity::withStubs()->where('user_id', $demo->id)->count())->toBe(1);
});
