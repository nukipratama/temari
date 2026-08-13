<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('run_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->text('answer')->nullable();
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'id'], 'run_questions_activity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_questions');
    }
};
