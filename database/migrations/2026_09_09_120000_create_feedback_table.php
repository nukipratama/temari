<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('note', 280)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id'], 'feedback_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
