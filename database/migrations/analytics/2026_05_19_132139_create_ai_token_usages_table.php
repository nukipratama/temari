<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_token_usages on the dedicated `analytics` schema.
 *
 * Consolidated from the four original app-DB migrations: their net shape was a
 * nullable bare-integer user_id (the FK to `users` was added then dropped). A
 * cross-schema FK is impossible here anyway, so this just creates the final
 * shape directly. Always run with `--database=analytics` (or, under tests, via
 * the testing-only loadMigrationsFrom in AppServiceProvider against the test DB).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_token_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 64);
            $table->unsignedInteger('prompt_tokens');
            $table->unsignedInteger('completion_tokens');
            $table->unsignedInteger('total_tokens');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('model', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Date-range filter + GROUP BY kind on the dashboard query.
            $table->index(['created_at', 'kind']);
            // Per-user breakdown.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_usages');
    }
};
