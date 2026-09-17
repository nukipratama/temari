<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a row currently served by the rule-based filler was served that way, not
 * merely that it was ({@see \App\Models\AI\Analysis::$served_by} already says
 * that). Reuses {@see \App\Services\AI\AnalysisOrigin} rather than a parallel
 * enum; only ever written as `Return` today (the athlete's return job filling
 * what it deferred) and null for every other rule-based reason (demo, cost
 * ceiling, backfill age cap, pre-connect, a content-filter fallback). Cleared
 * whenever the row is re-served by the LLM.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->string('rule_based_reason', 16)->nullable()->after('served_by');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->dropColumn('rule_based_reason');
        });
    }
};
