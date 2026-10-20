<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a narration actually cost, beyond the three totals already recorded.
 *
 * Every narrator is a tool-calling agent, so one row can cover several model
 * turns: `steps` tells an expensive block from a chatty one, `cached_tokens`
 * bills at a discount and `reasoning_tokens` bills as output.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->unsignedInteger('cached_tokens')->default(0)->after('total_tokens');
            $table->unsignedInteger('reasoning_tokens')->default(0)->after('cached_tokens');
            $table->unsignedSmallInteger('steps')->default(0)->after('reasoning_tokens');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->dropColumn(['cached_tokens', 'reasoning_tokens', 'steps']);
        });
    }
};
