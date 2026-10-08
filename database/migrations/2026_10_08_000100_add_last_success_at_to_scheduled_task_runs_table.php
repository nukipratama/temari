<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->timestamp('last_success_at')->nullable()->after('last_run_at');
        });

        DB::table('scheduled_task_runs')->update(['last_success_at' => DB::raw('last_run_at')]);
    }

    public function down(): void
    {
        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->dropColumn('last_success_at');
        });
    }
};
