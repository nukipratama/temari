<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\ActivityFetcher;
use App\Services\Strava\StravaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

function makeConnection(): StravaConnection
{
    return StravaConnection::factory()->create([
        'access_token' => 'tok',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);
}

it('returns new run ids sorted ascending', function (): void {
    $connection = makeConnection();
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push([
                ['id' => 30, 'sport_type' => 'Run'],
                ['id' => 20, 'sport_type' => 'Run'],
                ['id' => 10, 'sport_type' => 'Run'],
            ])
            ->push([]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection);

    expect($ids)->toBe([10, 20, 30]);
});

it('stops paginating as soon as it hits an existing activity', function (): void {
    $connection = makeConnection();
    Activity::factory()->for($connection->user)->create(['strava_external_id' => 100]);

    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push([
                ['id' => 200, 'sport_type' => 'Run'],
                ['id' => 150, 'sport_type' => 'Run'],
                ['id' => 100, 'sport_type' => 'Run'], // known stop marker
                ['id' => 99, 'sport_type' => 'Run'],  // never reached
            ]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection);

    expect($ids)->toBe([150, 200]);
    Http::assertSentCount(1); // pagination short-circuited
});

it('filters non-run sport types out', function (): void {
    $connection = makeConnection();
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push([
                ['id' => 1, 'sport_type' => 'Ride'],
                ['id' => 2, 'sport_type' => 'Run'],
                ['id' => 3, 'sport_type' => 'Swim'],
                ['id' => 4, 'sport_type' => 'TrailRun'],
                ['id' => 5, 'sport_type' => 'VirtualRun'],
            ])
            ->push([]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection);

    expect($ids)->toBe([2, 4, 5]);
});

it('respects per-user scoping (other users\' activities do not act as stop markers)', function (): void {
    $userA = User::factory()->create();
    StravaConnection::factory()->for($userA)->create([
        'access_token' => 'tokA',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);
    Activity::factory()->for($userA)->create(['strava_external_id' => 100]);

    // User B is fetching; their list has id=100, which is User A's, not User B's
    $userB = User::factory()->create();
    $connectionB = StravaConnection::factory()->for($userB)->create([
        'access_token' => 'tokB',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);

    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push([
                ['id' => 100, 'sport_type' => 'Run'],
                ['id' => 99, 'sport_type' => 'Run'],
            ])
            ->push([]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connectionB);

    expect($ids)->toBe([99, 100]);
});

it('returns empty list when athlete has no activities', function (): void {
    $connection = makeConnection();
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::response([]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection);

    expect($ids)->toBe([]);
});
