<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('compliance_score')->nullable()->after('status');
            $table->boolean('skipped')->default(false)->after('pinned');
            $table->boolean('ran_anyway')->default(false)->after('compliance_score');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn(['compliance_score', 'skipped', 'ran_anyway']);
        });
    }
};
