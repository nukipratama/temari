<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use App\Support\Devtools;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('devtools.admin_strava_ids', [12345, 67890]);
});

it('returns false for a null user', function (): void {
    expect(Devtools::isAdmin(null))->toBeFalse();
});

it('returns false for a user with no strava connection', function (): void {
    $user = User::factory()->create();

    expect(Devtools::isAdmin($user))->toBeFalse();
});

it('returns false for a user whose athlete id is not in the allow-list', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 99999]);

    expect(Devtools::isAdmin($user->fresh()))->toBeFalse();
});

it('returns true for a user whose athlete id is in the allow-list', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 12345]);

    expect(Devtools::isAdmin($user->fresh()))->toBeTrue();
});
