<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The eased distance an athlete was actually told to run, on a day the
 * readiness clamp stepped down before they had run at all.
 *
 * The clamp is otherwise render-only and cannot be reconstructed afterwards:
 * it derives from a ceiling that counts the day's own runs, so the ceiling
 * behind an 08:00 clamp no longer exists when compliance settles at 00:03.
 * `rest_clamped_at` already records the full-rest case; this records the
 * distance for the downgrades that still ask for a run.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->double('clamped_km')->nullable()->after('prescribed_km');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn('clamped_km');
        });
    }
};
