<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per superseded narration: the content an `ai_analyses` row held just
 * before a re-narration overwrote it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('analysis_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained('ai_analyses')->cascadeOnDelete();
            $table->longText('content');
            $table->string('fingerprint', 40)->nullable();
            $table->string('served_by', 16)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_versions');
    }
};
