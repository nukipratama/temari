<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Scheduler heartbeat: one row per scheduled command, upserted by a global
        // ScheduledTaskFinished/Failed listener. Lives on the default connection
        // (shared by app/horizon/scheduler) so the Pulse SchedulerHealth card sees
        // the latest run regardless of which container executed it.
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('command')->unique();
            $table->string('expression')->nullable();
            $table->string('last_status')->default('ok');
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
