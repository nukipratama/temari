<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Azure output-side content-filter trip that survived the
 * strip-and-retry and degraded to the rule-based filler in
 * {@see \App\Jobs\AI\AnalyzeRowJob}. Lives on the `analytics` connection
 * alongside `ai_token_usages`, so /devtools/ai-usage can query both over the
 * same date range without a cross-schema join.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_content_filter_events', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_content_filter_events');
    }
};
