<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('no-ops when the Strava kill-switch is off', function (): void {
    User::factory()->withStravaConnection()->create();
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldNotReceive('syncUser');
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync')->assertSuccessful();
});

it('syncs all users with a Strava connection', function (): void {
    User::factory()->withStravaConnection()->count(2)->create();
    User::factory()->create(); // no connection — should be skipped

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')->twice()->andReturn(0);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync')->assertSuccessful();
});

it('keeps syncing other users and still succeeds when one connection throws', function (): void {
    User::factory()->withStravaConnection()->count(2)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    // First user blows up (a transient API error or an open breaker), the second
    // still syncs; the scheduled command must not exit non-zero on one bad user.
    $orchestrator->shouldReceive('syncUser')->once()->andThrow(new RuntimeException('boom'));
    $orchestrator->shouldReceive('syncUser')->once()->andReturn(1);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync')->assertSuccessful();
});

it('honors the --user filter', function (): void {
    [$userA] = User::factory()->withStravaConnection()->count(2)->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->withArgs(fn (User $arg): bool => $arg->is($userA))
        ->andReturn(2);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync', ['--user' => $userA->id])
        ->expectsOutputToContain("user {$userA->id}: 2 new activities queued")
        ->assertSuccessful();
});

it('warns when no users with Strava connection exist', function (): void {
    $this->artisan('strava:sync')
        ->expectsOutputToContain('No users with a Strava connection found.')
        ->assertSuccessful();
});

it('skips users whose connection is revoked', function (): void {
    $active = User::factory()->create();
    StravaConnection::factory()->for($active)->create();
    $revoked = User::factory()->create();
    StravaConnection::factory()->for($revoked)->revoked()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->withArgs(fn (User $arg): bool => $arg->is($active))
        ->andReturn(0);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync')->assertSuccessful();
});

it('parses the --since option and forwards it to the orchestrator', function (): void {
    $user = User::factory()->withStravaConnection()->create();

    $orchestrator = Mockery::mock(SyncOrchestrator::class);
    $orchestrator->shouldReceive('syncUser')
        ->once()
        ->withArgs(fn (User $arg, ?CarbonImmutable $since): bool => $arg->is($user)
            && $since instanceof CarbonImmutable
            && $since->toDateString() === '2026-05-01')
        ->andReturn(0);
    $this->app->instance(SyncOrchestrator::class, $orchestrator);

    $this->artisan('strava:sync', ['--since' => '2026-05-01'])->assertSuccessful();
});
