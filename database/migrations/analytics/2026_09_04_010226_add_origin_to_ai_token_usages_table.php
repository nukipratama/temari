<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            // `kind` names the narrator; this names what started the call, so
            // spend can be attributed to the ingest cascade, a scheduled
            // command, a user's "Reread" or the hourly self-heal.
            $table->string('origin', 32)->default('unknown')->after('kind');

            // The dashboard filters and groups by origin over a date range, the
            // same shape the (created_at, kind) index serves for narrators.
            $table->index(['created_at', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'origin']);
            $table->dropColumn('origin');
        });
    }
};
