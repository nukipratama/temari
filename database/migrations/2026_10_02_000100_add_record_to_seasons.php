<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->unsignedTinyInteger('process_pct')->nullable()->after('block_goals_appended_at');
            $table->string('performance_state', 20)->nullable()->after('process_pct');
            $table->timestamp('record_settled_at')->nullable()->after('performance_state');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn(['process_pct', 'performance_state', 'record_settled_at']);
        });
    }
};
