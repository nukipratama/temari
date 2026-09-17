<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The eased pace an athlete was actually told to run, on a day the readiness
 * clamp leaves type and distance alone (an Easy day at an EasyOnly ceiling, a
 * Long day at ModerateOk) but still asks for a gentler pace — the slow end of
 * the easy band rather than the day's usual prescription.
 *
 * Recorded once, the same way `clamped_km`/`rest_clamped_at` already are: the
 * ceiling that produced it no longer exists once compliance settles the next
 * morning.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->integer('eased_pace_sec_per_km')->nullable()->after('clamped_km');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn('eased_pace_sec_per_km');
        });
    }
};
