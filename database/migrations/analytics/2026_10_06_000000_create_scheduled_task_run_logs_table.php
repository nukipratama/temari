<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->create('scheduled_task_run_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('command');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->string('skipped_reason', 16)->nullable();

            $table->index(['command', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('scheduled_task_run_logs');
    }
};
