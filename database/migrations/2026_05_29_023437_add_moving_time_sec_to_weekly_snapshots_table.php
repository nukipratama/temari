<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('weekly_snapshots', function (Blueprint $table) {
            // Total moving seconds for the week, so weekly pace can be computed
            // as Σ moving_time / Σ distance instead of a TRIMP proxy.
            $table->unsignedInteger('moving_time_sec')->nullable()->after('runs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_snapshots', function (Blueprint $table) {
            $table->dropColumn('moving_time_sec');
        });
    }
};
