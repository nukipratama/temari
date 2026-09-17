<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('distance_score')->nullable()->after('compliance_score');
        });

        DB::table('planned_sessions')->whereNotNull('compliance_score')->update(['distance_score' => DB::raw('compliance_score')]);
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn('distance_score');
        });
    }
};
