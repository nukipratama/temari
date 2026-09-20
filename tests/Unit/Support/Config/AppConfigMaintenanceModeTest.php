<?php

declare(strict_types=1);

use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use App\Support\Config\AppConfigMaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

uses(RefreshDatabase::class);

it('is the maintenance driver the application resolves', function (): void {
    expect(app()->maintenanceMode())->toBeInstanceOf(AppConfigMaintenanceMode::class);
});

it('is off until activated, and stores the flag in the durable app_config table', function (): void {
    $driver = new AppConfigMaintenanceMode();
    expect($driver->active())->toBeFalse();

    $driver->activate(['secret' => 'ignored']);

    expect($driver->active())->toBeTrue()
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(DB::table('app_config')->where('key', AppConfigKey::MaintenanceEnabled->value)->value('value'))->toBe('true')
        ->and($driver->data())->toBe([]);

    $driver->deactivate();

    expect($driver->active())->toBeFalse();
});

it('sees a write-through flip made by another container even after the same process has read the flag', function (): void {
    $driver = new AppConfigMaintenanceMode();
    $driver->activate([]);
    expect(app(AppConfig::class)->boolean(AppConfigKey::MaintenanceEnabled))->toBeTrue();

    new AppConfig()->set(AppConfigKey::MaintenanceEnabled, false);

    expect($driver->active())->toBeFalse();
});

it('keeps maintenance active from the cached last-known value without consulting MySQL', function (): void {
    $driver = new AppConfigMaintenanceMode();
    $driver->activate([]);
    DB::shouldReceive('table')->never();

    expect($driver->active())->toBeTrue();
});

it('fails closed when neither Redis nor MySQL can answer', function (): void {
    Log::spy();
    Cache::shouldReceive('get')
        ->once()
        ->with(AppConfigKey::MaintenanceEnabled->cacheKey())
        ->andThrow(new RuntimeException('redis down'));
    DB::shouldReceive('table')
        ->once()
        ->with('app_config')
        ->andThrow(new QueryException('mysql', 'select value from app_config', [], new PDOException('mysql down')));

    expect(new AppConfigMaintenanceMode()->active())->toBeTrue();

    Log::shouldHaveReceived('warning')->with('app_config.cache_unavailable', Mockery::type('array'));
    Log::shouldHaveReceived('warning')->with('maintenance.flag_unreadable', Mockery::type('array'));
});
