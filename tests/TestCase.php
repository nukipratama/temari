<?php

namespace Tests;

use App\Support\Config\AppConfigKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Override;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roll back the `analytics` connection too, not just the default. It's a
     * separate PDO, so RefreshDatabase won't wrap it otherwise and
     * ai_token_usages writes would leak across tests.
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = ['mysql', 'analytics'];

    private const int REDIS_DB_STRIDE_PER_WORKER = 32;

    private const int REDIS_CACHE_DB_OFFSET = 16;

    /**
     * Give each parallel worker its own Redis databases right after the config loads: a provider
     * resolves the Redis manager while booting, and the manager copies the config when it is built.
     */
    #[Override]
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app->make('config');
            $default = (int) $config->get('database.redis.default.database')
                + (int) ($_SERVER['TEST_TOKEN'] ?? 0) * self::REDIS_DB_STRIDE_PER_WORKER;

            $config->set('database.redis.default.database', $default);
            $config->set('database.redis.cache.database', $default + self::REDIS_CACHE_DB_OFFSET);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();

        foreach (AppConfigKey::cases() as $key) {
            Cache::forget($key->cacheKey());
        }

        Cache::forever(AppConfigKey::MaintenanceEnabled->cacheKey(), ['__config' => false]);

        config(['notifications.hold_during_quiet_hours' => false]);
    }

    /**
     * Point the `analytics` connection at the default test database (including
     * the paratest per-process suffix) before RefreshDatabase boots, so its
     * tables are migrated and transaction-wrapped alongside the default ones.
     * Runs after ParallelTesting has already switched the default connection.
     */
    #[Override]
    protected function setUpTraits()
    {
        config(['database.connections.analytics.database' => config('database.connections.mysql.database')]);
        DB::purge('analytics');

        return parent::setUpTraits();
    }
}
