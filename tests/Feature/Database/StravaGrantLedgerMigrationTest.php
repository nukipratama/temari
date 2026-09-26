<?php

declare(strict_types=1);

use App\Enums\StravaGrantEventType;
use App\Models\StravaGrantEvent;
use App\Models\StravaGrantToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills active connections into the grant ledger and skips revoked connections', function (): void {
    $connection = 'strava_ledger_migration_test';
    $originalConnection = DB::getDefaultConnection();
    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('strava_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('strava_athlete_id');
            $table->unsignedInteger('credential_version');
            $table->text('refresh_token');
            $table->dateTime('revoked_at')->nullable();
        });

        DB::table('strava_connections')->insert([
            [
                'id' => 1,
                'user_id' => 7,
                'strava_athlete_id' => 12345,
                'credential_version' => 6,
                'refresh_token' => Crypt::encryptString('active-refresh'),
                'revoked_at' => null,
            ],
            [
                'id' => 2,
                'user_id' => 8,
                'strava_athlete_id' => 54321,
                'credential_version' => 2,
                'refresh_token' => Crypt::encryptString('revoked-refresh'),
                'revoked_at' => now(),
            ],
        ]);

        $migration = require base_path('database/migrations/2026_09_26_000002_create_strava_grant_ledger_tables.php');
        $migration->up();

        $grant = StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole();
        $event = StravaGrantEvent::query()->where('strava_athlete_id', 12345)->sole();

        expect($grant->user_id)->toBe(7)
            ->and($grant->credential_version)->toBe(6)
            ->and($grant->refresh_token)->toBe('active-refresh')
            ->and($event->event)->toBe(StravaGrantEventType::Granted)
            ->and($event->credential_version)->toBe(6)
            ->and(StravaGrantToken::query()->where('strava_athlete_id', 54321)->exists())->toBeFalse()
            ->and(StravaGrantEvent::query()->where('strava_athlete_id', 54321)->exists())->toBeFalse()
            ->and(Schema::getForeignKeys('strava_grant_events'))->toBe([])
            ->and(Schema::getForeignKeys('strava_grant_tokens'))->toBe([]);
    } finally {
        DB::purge($connection);
        DB::setDefaultConnection($originalConnection);
    }
});
