<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which producer wrote the content on this row: the LLM, or the deterministic
 * rule-based filler. Nullable because a row that has never been Done was served
 * by neither. See {@see \App\Services\AI\ServedBy}.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->string('served_by', 16)->nullable()->after('content_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->dropColumn('served_by');
        });
    }
};
