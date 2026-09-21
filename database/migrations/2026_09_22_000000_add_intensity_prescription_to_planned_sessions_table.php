<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('prescribed_hard_minutes')->nullable()->after('race_distance_m');
            $table->string('prescribed_pace_band')->nullable()->after('prescribed_hard_minutes');
            $table->unsignedSmallInteger('prescribed_pace_sec_per_km')->nullable()->after('prescribed_pace_band');
            $table->string('prescription_reason')->nullable()->after('prescribed_pace_sec_per_km');
            $table->json('prescription_race_context')->nullable()->after('prescription_reason');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'prescribed_hard_minutes',
                'prescribed_pace_band',
                'prescribed_pace_sec_per_km',
                'prescription_reason',
                'prescription_race_context',
            ]);
        });
    }
};
