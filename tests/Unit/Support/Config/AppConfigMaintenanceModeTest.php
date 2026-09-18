<?php

declare(strict_types=1);

use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use App\Support\Config\AppConfigMaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

it('sees a flip made by another container even after the same process has read the flag', function (): void {
    $driver = new AppConfigMaintenanceMode();
    $driver->activate([]);
    expect(app(AppConfig::class)->boolean(AppConfigKey::MaintenanceEnabled))->toBeTrue();

    // A paused queue worker never flushes its scoped AppConfig, so a stale memo
    // would hold it paused forever. Flip the row the way `artisan up` in
    // another container would.
    DB::table('app_config')->where('key', AppConfigKey::MaintenanceEnabled->value)->update(['value' => 'false']);

    expect($driver->active())->toBeFalse();
});
