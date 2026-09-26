<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('strava_grant_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('strava_athlete_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('credential_version');
            $table->string('event', 32);
            $table->text('error')->nullable();
            $table->dateTime('created_at');
            $table->index(['strava_athlete_id', 'id']);
        });

        Schema::create('strava_grant_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('strava_athlete_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('credential_version');
            $table->text('refresh_token');
            $table->timestamps();
        });

        DB::transaction(function (): void {
            $now = now();

            DB::table('strava_connections')
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->chunkById(100, function ($connections) use ($now): void {
                    foreach ($connections as $connection) {
                        DB::table('strava_grant_events')->insert([
                            'strava_athlete_id' => $connection->strava_athlete_id,
                            'user_id' => $connection->user_id,
                            'credential_version' => $connection->credential_version,
                            'event' => 'granted',
                            'created_at' => $now,
                        ]);

                        DB::table('strava_grant_tokens')->insert([
                            'strava_athlete_id' => $connection->strava_athlete_id,
                            'user_id' => $connection->user_id,
                            'credential_version' => $connection->credential_version,
                            'refresh_token' => $connection->refresh_token,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strava_grant_tokens');
        Schema::dropIfExists('strava_grant_events');
    }
};
