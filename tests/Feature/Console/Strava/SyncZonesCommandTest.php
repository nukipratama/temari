<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\ZoneFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pulse\Facades\Pulse;

uses(RefreshDatabase::class);

it('syncs zones for an eligible connected user', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $newZones = [
        'Z1' => ['lo' => 100, 'hi' => 125],
        'Z2' => ['lo' => 125, 'hi' => 145],
        'Z3' => ['lo' => 145, 'hi' => 165],
        'Z4' => ['lo' => 165, 'hi' => 180],
        'Z5' => ['lo' => 180, 'hi' => 999],
    ];

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn($newZones);
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();

    $profile = RunnerProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->source)->toBe('strava')
        ->and($profile->hr_zones)->toEqual($newZones);
});

it('skips the demo user', function (): void {
    $user = User::factory()->demo()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();
});

it('skips a user whose runner_profile source is manual', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);
    RunnerProfile::factory()->for($user)->create(['source' => 'manual']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();
});

it('skips a user whose connection lacks profile:read_all', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();
});

it('skips a revoked connection', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();
});

it('honors the --user filter', function (): void {
    $userA = User::factory()->create();
    StravaConnection::factory()->for($userA)->create(['scopes' => 'read,activity:read_all,profile:read_all']);
    $userB = User::factory()->create();
    StravaConnection::factory()->for($userB)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn(null);
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones', ['--user' => $userA->id])->assertSuccessful();
});

it('warns when no eligible users exist', function (): void {
    $this->artisan('strava:sync-zones')
        ->expectsOutputToContain('No eligible users with a Strava connection found.')
        ->assertSuccessful();
});

it('marks the connection revoked when the fetcher reports a 401, unlike a generic failure', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andThrow(new StravaConnectionRevokedException('401'));
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();

    expect($user->stravaConnection()->first()->isRevoked())->toBeTrue();
});

it('ignores a stale 401 after credentials change during the zone fetch', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create([
        'credential_version' => 2,
        'scopes' => 'read,activity:read_all,profile:read_all',
    ]);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturnUsing(function () use ($connection): never {
        $connection->update(['credential_version' => 3, 'revoked_at' => null]);

        throw new StravaConnectionRevokedException('401 unauthorized');
    });
    $this->app->instance(ZoneFetcher::class, $fetcher);
    Pulse::shouldReceive('record')->never();

    $this->artisan('strava:sync-zones')
        ->expectsOutputToContain('ignored stale auth failure after credentials changed')
        ->assertSuccessful();

    expect($connection->fresh()->isRevoked())->toBeFalse();
});

it('keeps syncing other users and still succeeds when one connection throws', function (): void {
    $userA = User::factory()->create();
    StravaConnection::factory()->for($userA)->create(['scopes' => 'read,activity:read_all,profile:read_all']);
    $userB = User::factory()->create();
    StravaConnection::factory()->for($userB)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andThrow(new RuntimeException('boom'));
    $fetcher->shouldReceive('fetch')->once()->andReturn(null);
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')->assertSuccessful();
});

it('keeps a manual save that lands during the Strava fetch', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);
    $manualZones = config('runner.hr_zones');
    $manualZones['Z5'] = ['lo' => 181, 'hi' => 999];

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturnUsing(function () use ($user, $manualZones): array {
        RunnerProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['source' => 'manual', 'hr_zones' => $manualZones, 'max_hr' => 190, 'resting_hr' => 50],
        );

        return [
            'Z1' => ['lo' => 100, 'hi' => 125],
            'Z2' => ['lo' => 125, 'hi' => 145],
            'Z3' => ['lo' => 145, 'hi' => 165],
            'Z4' => ['lo' => 165, 'hi' => 180],
            'Z5' => ['lo' => 180, 'hi' => 999],
        ];
    });
    $this->app->instance(ZoneFetcher::class, $fetcher);

    $this->artisan('strava:sync-zones')
        ->expectsOutputToContain('zones unchanged or set manually, skipped')
        ->assertSuccessful();

    $profile = RunnerProfile::query()->where('user_id', $user->id)->sole();
    expect($profile->source)->toBe('manual')
        ->and($profile->hr_zones)->toEqual($manualZones);
});
