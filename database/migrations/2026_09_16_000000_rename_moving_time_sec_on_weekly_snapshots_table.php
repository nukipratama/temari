<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly pace is narrated through `get_week_totals`, and every duration the
 * app shows is elapsed time, so the weekly total moves to the same clock.
 * Rows written before this hold moving-time sums; `WeeklyAggregator` rewrites
 * a week whenever an activity on it changes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('weekly_snapshots', function (Blueprint $table): void {
            $table->renameColumn('moving_time_sec', 'elapsed_time_sec');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_snapshots', function (Blueprint $table): void {
            $table->renameColumn('elapsed_time_sec', 'moving_time_sec');
        });
    }
};
