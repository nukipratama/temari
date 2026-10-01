<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->json('readiness_assessment')->nullable()->after('eased_pace_sec_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn('readiness_assessment');
        });
    }
};
