<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            // Capture-only Strava detail fields we may want later. Archived on
            // ingest to avoid a rate-limited re-fetch of history if a future
            // feature needs them. Not surfaced in the UI unless a feature uses it.
            $table->unsignedSmallInteger('suffer_score')->nullable()->after('trimp_edwards');
            $table->unsignedTinyInteger('workout_type')->nullable()->after('suffer_score');
            $table->float('elev_high')->nullable()->after('total_elevation_gain');
            $table->float('elev_low')->nullable()->after('elev_high');
            $table->string('device_name')->nullable()->after('workout_type');
            $table->float('average_watts')->nullable()->after('device_name');
            $table->float('max_speed')->nullable()->after('average_watts');
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropColumn([
                'suffer_score',
                'workout_type',
                'elev_high',
                'elev_low',
                'device_name',
                'average_watts',
                'max_speed',
            ]);
        });
    }
};
