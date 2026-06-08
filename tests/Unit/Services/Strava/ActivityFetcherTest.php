<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\ActivityFetcher;
use App\Services\Strava\StravaClient;
use Carbon\CarbonImmutable;
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
                ['id' => 100, 'sport_type' => 'Run'],
                ['id' => 99, 'sport_type' => 'Run'],
            ]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection);

    expect($ids)->toBe([150, 200]);
    Http::assertSentCount(1);
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

it('skips items with missing or zero ids', function (): void {
    $connection = makeConnection();
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push([
                ['sport_type' => 'Run'],
                ['id' => 0, 'sport_type' => 'Run'],
                ['id' => 42, 'sport_type' => 'Run'],
            ])
            ->push([]),
    ]);

    expect((new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection))->toBe([42]);
});

it('stops at the first activity started on or before the --since bound', function (): void {
    $connection = makeConnection();
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push([
                ['id' => 30, 'sport_type' => 'Run', 'start_date' => '2026-05-10T06:00:00Z'],
                ['id' => 20, 'sport_type' => 'Run', 'start_date' => '2026-05-05T06:00:00Z'],
                ['id' => 10, 'sport_type' => 'Run', 'start_date' => '2026-04-20T06:00:00Z'],
            ]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))
        ->fetchNewExternalIds($connection, CarbonImmutable::parse('2026-05-01T00:00:00Z'));

    // id 10 (2026-04-20) is on/before the bound → walk stops, it is excluded.
    expect($ids)->toBe([20, 30]);
    Http::assertSentCount(1);
});

it('paginates beyond page 1 when a full page returns', function (): void {
    $connection = makeConnection();
    // 200 items == PER_PAGE → forces page 2.
    $firstPage = array_map(
        fn (int $i): array => ['id' => 1000 + $i, 'sport_type' => 'Run'],
        range(0, 199),
    );
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::sequence()
            ->push($firstPage)
            ->push([
                ['id' => 500, 'sport_type' => 'Run'],
            ])
            ->push([]),
    ]);

    $ids = (new ActivityFetcher(new StravaClient()))->fetchNewExternalIds($connection);

    expect($ids)->toContain(500)
        ->and(count($ids))->toBe(201);
    Http::assertSentCount(2);
});
