<?php

declare(strict_types=1);

use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the code default when no row exists', function (): void {
    expect((new AppConfig())->get(AppConfigKey::AiEnabled))->toBeTrue()
        ->and((new AppConfig())->get(AppConfigKey::StravaBreakerThreshold))->toBe(5);
});

it('lets a stored row override the default', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::AiEnabled, false);

    // Fresh instance proves it round-tripped through the DB, not just the memo.
    expect((new AppConfig())->get(AppConfigKey::AiEnabled))->toBeFalse();
});

it('upserts in place rather than inserting duplicate rows', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::StravaBreakerThreshold, 3);
    $config->set(AppConfigKey::StravaBreakerThreshold, 8);

    expect(DB::table('app_config')->where('key', 'strava.breaker.threshold')->count())->toBe(1)
        ->and((new AppConfig())->integer(AppConfigKey::StravaBreakerThreshold))->toBe(8);
});

it('reflects a set value immediately on the same instance (memo updated)', function (): void {
    $config = new AppConfig();
    expect($config->boolean(AppConfigKey::StravaEnabled))->toBeTrue();

    $config->set(AppConfigKey::StravaEnabled, false);

    expect($config->boolean(AppConfigKey::StravaEnabled))->toBeFalse();
});

it('casts stored values back to their canonical type', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::StravaBreakerOpenedAt, '2026-06-09T10:00:00+00:00');

    expect((new AppConfig())->get(AppConfigKey::StravaBreakerOpenedAt))
        ->toBe('2026-06-09T10:00:00+00:00');
});
