<?php

declare(strict_types=1);

use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('returns the code default when no row exists', function (): void {
    expect(new AppConfig()->get(AppConfigKey::AiEnabled))->toBeTrue()
        ->and(new AppConfig()->get(AppConfigKey::StravaBreakerThreshold))->toBe(5);
});

it('lets a stored row override the default', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::AiEnabled, false);

    // Fresh instance proves it round-tripped through the DB, not just the memo.
    expect(new AppConfig()->get(AppConfigKey::AiEnabled))->toBeFalse();
});

it('serves every key from the shared cache after its first database read', function (): void {
    DB::table('app_config')->insert([
        'key' => AppConfigKey::AiEnabled->value,
        'value' => 'false',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(new AppConfig()->boolean(AppConfigKey::AiEnabled))->toBeFalse();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(new AppConfig()->boolean(AppConfigKey::AiEnabled))->toBeFalse()
        ->and($queries)->toBe(0);
});

it('writes through so a fresh process sees a toggle immediately', function (): void {
    $writer = new AppConfig();
    $writer->set(AppConfigKey::StravaEnabled, false);

    expect(Cache::get(AppConfigKey::StravaEnabled->cacheKey()))
        ->toBe(['__config' => false])
        ->and(new AppConfig()->boolean(AppConfigKey::StravaEnabled))->toBeFalse();
});

it('expires cached values within the documented bound', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::AiEnabled, false);

    DB::table('app_config')->where('key', AppConfigKey::AiEnabled->value)->update(['value' => 'true']);
    expect(new AppConfig()->boolean(AppConfigKey::AiEnabled))->toBeFalse();

    $this->travel(AppConfig::CACHE_TTL_SECONDS + 1)->seconds();

    expect(new AppConfig()->boolean(AppConfigKey::AiEnabled))->toBeTrue();
});

it('falls back to MySQL when the cache is unavailable', function (): void {
    DB::table('app_config')->insert([
        'key' => AppConfigKey::AiEnabled->value,
        'value' => 'false',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Cache::shouldReceive('get')->once()->with(AppConfigKey::AiEnabled->cacheKey())->andThrow(new RuntimeException('redis down'));
    Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('redis down'));

    expect(new AppConfig()->boolean(AppConfigKey::AiEnabled))->toBeFalse();
});

it('keeps a successful MySQL write when the cache write fails', function (): void {
    Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('redis down'));

    new AppConfig()->set(AppConfigKey::StravaEnabled, false);

    expect(DB::table('app_config')->where('key', AppConfigKey::StravaEnabled->value)->value('value'))
        ->toBe('false');
});

it('serves the last known cached value when MySQL is unavailable', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::MaintenanceEnabled, true);
    Schema::drop('app_config');

    expect(new AppConfig()->boolean(AppConfigKey::MaintenanceEnabled))->toBeTrue();
});

it('surfaces a MySQL failure when the cache is also unavailable', function (): void {
    Schema::drop('app_config');
    Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('redis down'));

    expect(fn () => new AppConfig()->boolean(AppConfigKey::AiEnabled))
        ->toThrow(QueryException::class);
});

it('upserts in place rather than inserting duplicate rows', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::StravaBreakerThreshold, 3);
    $config->set(AppConfigKey::StravaBreakerThreshold, 8);

    expect(DB::table('app_config')->where('key', 'strava.breaker.threshold')->count())->toBe(1)
        ->and(new AppConfig()->integer(AppConfigKey::StravaBreakerThreshold))->toBe(8);
});

it('forget drops the shared value so the next get re-reads from the DB', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::StravaBreakerThreshold, 3);
    expect($config->integer(AppConfigKey::StravaBreakerThreshold))->toBe(3);

    DB::table('app_config')->where('key', 'strava.breaker.threshold')->update(['value' => json_encode(9)]);
    expect($config->integer(AppConfigKey::StravaBreakerThreshold))->toBe(3);

    $config->forget(AppConfigKey::StravaBreakerThreshold);
    expect($config->integer(AppConfigKey::StravaBreakerThreshold))->toBe(9);
});

it('setMany writes multiple keys atomically in a single call', function (): void {
    $config = new AppConfig();
    $config->setMany([
        [AppConfigKey::StravaBreakerThreshold, 7],
        [AppConfigKey::AiEnabled, false],
    ]);

    $fresh = new AppConfig();
    expect($fresh->integer(AppConfigKey::StravaBreakerThreshold))->toBe(7)
        ->and($fresh->boolean(AppConfigKey::AiEnabled))->toBeFalse();
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

    expect(new AppConfig()->get(AppConfigKey::StravaBreakerOpenedAt))
        ->toBe('2026-06-09T10:00:00+00:00');
});
