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
use RuntimeException;

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

    private const int REDIS_WORKERS_PER_SLOT = 16;

    private const int REDIS_MAX_SLOT = 3;

    private const int REDIS_DBS_PER_SLOT = self::REDIS_WORKERS_PER_SLOT * 2;

    private const array TEST_REDIS_HOSTS = ['redis_test', 'temari-shared-redis-test', '127.0.0.1'];

    /**
     * @return array{int, int} the default and cache Redis database of one worker in one slot
     */
    public static function redisDatabases(int $slotBase, int $token): array
    {
        if ($slotBase < 0 || $slotBase % self::REDIS_DBS_PER_SLOT !== 0 || intdiv($slotBase, self::REDIS_DBS_PER_SLOT) > self::REDIS_MAX_SLOT) {
            throw new RuntimeException("REDIS_DB {$slotBase} is not a slot base: use a multiple of ".self::REDIS_DBS_PER_SLOT.' up to '.self::REDIS_MAX_SLOT * self::REDIS_DBS_PER_SLOT.'.');
        }

        if ($token < 0 || $token >= self::REDIS_WORKERS_PER_SLOT) {
            throw new RuntimeException("TEST_TOKEN {$token} is out of range: at most ".(self::REDIS_WORKERS_PER_SLOT - 1).' parallel workers fit a slot.');
        }

        $default = $slotBase + $token * 2;

        return [$default, $default + 1];
    }

    public static function assertTestRedisHost(string $host): void
    {
        if (! in_array($host, self::TEST_REDIS_HOSTS, true)) {
            throw new RuntimeException("Refusing to flush Redis host '{$host}': tests only run against ".implode(', ', self::TEST_REDIS_HOSTS).'. Copy .env.testing.example to .env.testing.');
        }
    }

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

            self::assertTestRedisHost((string) $config->get('database.redis.default.host'));
            self::assertTestRedisHost((string) $config->get('database.redis.cache.host'));

            [$default, $cache] = self::redisDatabases(
                (int) $config->get('database.redis.default.database'),
                (int) ($_SERVER['TEST_TOKEN'] ?? 0),
            );

            $config->set('database.redis.default.database', $default);
            $config->set('database.redis.cache.database', $cache);
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
