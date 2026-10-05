<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->string('time_trial_outcome')->nullable()->after('fall_off_tilt');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn('time_trial_outcome');
        });
    }
};
