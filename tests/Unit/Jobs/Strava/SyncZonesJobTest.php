<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncZonesJob;
use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\ZoneFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes a strava-sourced profile, setting source and strava_zones_synced_at without touching max_hr/resting_hr', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    RunnerProfile::factory()->for($user)->create([
        'source' => 'strava',
        'max_hr' => 195,
        'resting_hr' => 48,
    ]);

    $newZones = [
        'Z1' => ['lo' => 100, 'hi' => 125],
        'Z2' => ['lo' => 125, 'hi' => 145],
        'Z3' => ['lo' => 145, 'hi' => 165],
        'Z4' => ['lo' => 165, 'hi' => 180],
        'Z5' => ['lo' => 180, 'hi' => 999],
    ];

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn($newZones);

    new SyncZonesJob($user->id)->handle($fetcher);

    $profile = RunnerProfile::query()->where('user_id', $user->id)->firstOrFail();

    expect($profile->source)->toBe('strava')
        ->and($profile->strava_zones_synced_at)->not->toBeNull()
        ->and($profile->hr_zones)->toEqual($newZones)
        ->and($profile->max_hr)->toBe(195)
        ->and($profile->resting_hr)->toBe(48);
});

it('skips a user whose profile source is manual', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    RunnerProfile::factory()->for($user)->create(['source' => 'manual', 'max_hr' => 200]);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');

    new SyncZonesJob($user->id)->handle($fetcher);

    expect(RunnerProfile::query()->where('user_id', $user->id)->value('max_hr'))->toBe(200);
});

it('overwrites a manual profile when forced (explicit user re-sync)', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    RunnerProfile::factory()->for($user)->create(['source' => 'manual', 'max_hr' => 200]);

    $newZones = [
        'Z1' => ['lo' => 100, 'hi' => 125],
        'Z2' => ['lo' => 125, 'hi' => 145],
        'Z3' => ['lo' => 145, 'hi' => 165],
        'Z4' => ['lo' => 165, 'hi' => 180],
        'Z5' => ['lo' => 180, 'hi' => 999],
    ];

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn($newZones);

    new SyncZonesJob($user->id, force: true)->handle($fetcher);

    expect(RunnerProfile::query()->where('user_id', $user->id)->value('source'))->toBe('strava');
});

it('no-ops when Strava returns no zones', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn(null);

    new SyncZonesJob($user->id)->handle($fetcher);

    expect(RunnerProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('no-ops when Strava zones are already the effective (default) zones', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn(config('runner.hr_zones'));

    new SyncZonesJob($user->id)->handle($fetcher);

    expect(RunnerProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('no-ops on a deleted user', function (): void {
    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');

    new SyncZonesJob(999_999)->handle($fetcher);
});

it('no-ops when the user has no Strava connection', function (): void {
    $user = User::factory()->create();

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');

    new SyncZonesJob($user->id)->handle($fetcher);
});
