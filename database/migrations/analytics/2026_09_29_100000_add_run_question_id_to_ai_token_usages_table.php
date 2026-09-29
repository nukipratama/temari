<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `run_question_id` is a bare indexed integer, not a foreign key: `run_questions`
 * lives on the app connection and this table on `analytics`, so the join is done
 * in PHP.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->unsignedBigInteger('run_question_id')->nullable()->after('analysis_id');

            $table->index('run_question_id');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->dropIndex(['run_question_id']);
            $table->dropColumn('run_question_id');
        });
    }
};
