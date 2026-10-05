<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->string('fall_off_tilt')->nullable()->after('prescription_race_context');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn('fall_off_tilt');
        });
    }
};
