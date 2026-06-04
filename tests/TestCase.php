<?php

namespace Tests;

use Override;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

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
