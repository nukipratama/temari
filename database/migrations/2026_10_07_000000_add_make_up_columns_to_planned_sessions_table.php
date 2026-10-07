<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->date('made_up_on')->nullable()->after('skipped');
            $table->foreignId('made_up_from_id')->nullable()->after('made_up_on')->constrained('planned_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('made_up_from_id');
            $table->dropColumn('made_up_on');
        });
    }
};
