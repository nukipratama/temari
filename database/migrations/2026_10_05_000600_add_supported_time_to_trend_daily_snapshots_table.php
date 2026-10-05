<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('trend_daily_snapshots', function (Blueprint $table): void {
            $table->foreignId('race_goal_id')->nullable()->after('pace_variability_sec')->constrained()->nullOnDelete();
            $table->unsignedInteger('supported_time_sec')->nullable()->after('race_goal_id');
            $table->unsignedInteger('supported_source_distance_m')->nullable()->after('supported_time_sec');
            $table->date('supported_source_date')->nullable()->after('supported_source_distance_m');
        });
    }

    public function down(): void
    {
        Schema::table('trend_daily_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('race_goal_id');
            $table->dropColumn(['supported_time_sec', 'supported_source_distance_m', 'supported_source_date']);
        });
    }
};
