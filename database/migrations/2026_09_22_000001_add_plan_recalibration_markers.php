<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('plan_recalibration_started_at')->nullable()->after('backfilled_at');
            $table->timestamp('plan_recalibration_completed_at')->nullable()->after('plan_recalibration_started_at');
        });

        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->timestamp('stale_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->dropColumn('stale_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['plan_recalibration_started_at', 'plan_recalibration_completed_at']);
        });
    }
};
