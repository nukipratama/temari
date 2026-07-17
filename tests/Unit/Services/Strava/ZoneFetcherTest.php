<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\StravaClient;
use App\Services\Strava\ZoneFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function stravaZonesConnection(array $overrides = []): StravaConnection
{
    return StravaConnection::factory()->create([
        'scopes' => 'read,activity:read_all,profile:read_all',
        'token_expires_at' => Carbon::now()->addHours(5),
        ...$overrides,
    ]);
}

it('parses the athlete zones response into the app hr_zones shape', function (): void {
    Http::fake([
        'www.strava.com/api/v3/athlete/zones' => Http::response([
            'heart_rate' => [
                'custom_zones' => true,
                'zones' => [
                    ['min' => 0, 'max' => 120],
                    ['min' => 120, 'max' => 140],
                    ['min' => 140, 'max' => 160],
                    ['min' => 160, 'max' => 175],
                    ['min' => 175, 'max' => -1],
                ],
            ],
        ]),
    ]);

    $connection = stravaZonesConnection();

    $zones = new ZoneFetcher(new StravaClient())->fetch($connection, 55);

    expect($zones)->toBe([
        'Z1' => ['lo' => 55, 'hi' => 120],
        'Z2' => ['lo' => 120, 'hi' => 140],
        'Z3' => ['lo' => 140, 'hi' => 160],
        'Z4' => ['lo' => 160, 'hi' => 175],
        'Z5' => ['lo' => 175, 'hi' => 999],
    ]);
});

it('lifts a rest-anchored Z1 floor up to the resting HR', function (): void {
    // Strava reports Z1 starting at 0 ("Rest"); the app requires Z1.lo >= resting.
    Http::fake([
        'www.strava.com/api/v3/athlete/zones' => Http::response([
            'heart_rate' => [
                'custom_zones' => true,
                'zones' => [
                    ['min' => 0, 'max' => 137],
                    ['min' => 137, 'max' => 153],
                    ['min' => 153, 'max' => 167],
                    ['min' => 167, 'max' => 175],
                    ['min' => 175, 'max' => -1],
                ],
            ],
        ]),
    ]);

    $zones = new ZoneFetcher(new StravaClient())->fetch(stravaZonesConnection(), 56);

    expect($zones['Z1'])->toBe(['lo' => 56, 'hi' => 137])
        ->and($zones['Z2'])->toBe(['lo' => 137, 'hi' => 153]);
});

it('leaves Z1 untouched when Strava already starts it above resting', function (): void {
    Http::fake([
        'www.strava.com/api/v3/athlete/zones' => Http::response([
            'heart_rate' => [
                'custom_zones' => true,
                'zones' => [
                    ['min' => 100, 'max' => 120],
                    ['min' => 120, 'max' => 140],
                    ['min' => 140, 'max' => 160],
                    ['min' => 160, 'max' => 175],
                    ['min' => 175, 'max' => -1],
                ],
            ],
        ]),
    ]);

    $zones = new ZoneFetcher(new StravaClient())->fetch(stravaZonesConnection(), 55);

    expect($zones['Z1'])->toBe(['lo' => 100, 'hi' => 120]);
});

it('soft-skips (returns null) on a 403, without throwing StravaConnectionRevokedException', function (): void {
    Http::fake([
        'www.strava.com/api/v3/athlete/zones' => Http::response(['message' => 'Forbidden'], 403),
    ]);

    $connection = stravaZonesConnection();

    $zones = null;
    $thrown = null;

    try {
        $zones = new ZoneFetcher(new StravaClient())->fetch($connection, 55);
    } catch (StravaConnectionRevokedException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull()
        ->and($zones)->toBeNull();
});

it('does not call Strava at all when the connection lacks profile:read_all', function (): void {
    Http::fake();

    $connection = stravaZonesConnection(['scopes' => 'read,activity:read_all']);

    $zones = new ZoneFetcher(new StravaClient())->fetch($connection, 55);

    expect($zones)->toBeNull();
    Http::assertNothingSent();
});

it('returns null when the zones payload is malformed', function (): void {
    Http::fake([
        'www.strava.com/api/v3/athlete/zones' => Http::response([
            'heart_rate' => ['zones' => [['min' => 0, 'max' => 120]]],
        ]),
    ]);

    $connection = stravaZonesConnection();

    $zones = new ZoneFetcher(new StravaClient())->fetch($connection, 55);

    expect($zones)->toBeNull();
});
