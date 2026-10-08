<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills the last success from the last run of every row, a failed one included', function (): void {
    $connection = 'scheduled_task_runs_last_success_migration_test';
    $originalConnection = DB::getDefaultConnection();
    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('command')->unique();
            $table->string('last_status')->default('ok');
            $table->timestamp('last_run_at')->nullable();
        });
        DB::table('scheduled_task_runs')->insert([
            ['command' => 'strava:sync', 'last_status' => 'ok', 'last_run_at' => '2026-10-07 18:00:00'],
            ['command' => 'race:remind', 'last_status' => 'failed', 'last_run_at' => '2026-10-07 18:00:00'],
            ['command' => 'plan:regenerate', 'last_status' => 'skipped', 'last_run_at' => '2026-10-07 00:26:00'],
        ]);

        $migration = require base_path('database/migrations/2026_10_08_000100_add_last_success_at_to_scheduled_task_runs_table.php');
        $migration->up();

        expect(DB::table('scheduled_task_runs')->pluck('last_success_at', 'command')->all())->toBe([
            'strava:sync' => '2026-10-07 18:00:00',
            'race:remind' => '2026-10-07 18:00:00',
            'plan:regenerate' => '2026-10-07 00:26:00',
        ]);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
});
