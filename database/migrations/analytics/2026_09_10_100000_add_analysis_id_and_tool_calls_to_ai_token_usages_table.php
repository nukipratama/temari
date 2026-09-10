<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `analysis_id` is a bare indexed integer, not a foreign key: `ai_analyses`
 * lives on the app connection and this table on `analytics`, so the join is
 * done in PHP. See docs/decisions/narration-analytics-are-joinable.md.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->unsignedBigInteger('analysis_id')->nullable()->after('user_id');
            $table->json('tool_calls')->nullable()->after('steps');

            $table->index('analysis_id');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->dropIndex(['analysis_id']);
            $table->dropColumn(['analysis_id', 'tool_calls']);
        });
    }
};
