<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('drops duplicate goals, keeping the oldest, before adding unique(season_id, metric)', function (): void {
    $connection = 'season_goals_unique_migration_test';
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
        Schema::create('season_goals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->string('metric');
        });
        DB::table('season_goals')->insert([
            ['id' => 1, 'season_id' => 10, 'metric' => 'season_peak_weekly_km'],
            ['id' => 2, 'season_id' => 10, 'metric' => 'season_peak_weekly_km'],
            ['id' => 3, 'season_id' => 10, 'metric' => 'season_race_goal_met'],
            ['id' => 4, 'season_id' => 11, 'metric' => 'season_peak_weekly_km'],
        ]);

        $migration = require database_path('migrations/2026_09_27_000001_add_unique_metric_to_season_goals_table.php');
        $migration->up();

        expect(DB::table('season_goals')->orderBy('id')->pluck('id')->all())->toBe([1, 3, 4])
            ->and(fn () => DB::table('season_goals')->insert(['season_id' => 10, 'metric' => 'season_race_goal_met']))
            ->toThrow(UniqueConstraintViolationException::class);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
});
