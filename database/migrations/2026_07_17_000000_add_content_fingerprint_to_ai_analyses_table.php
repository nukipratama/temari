<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            // xxh128 digest of the run data that materially drove this narration,
            // stamped at generation time. Lets a re-sync tell a real data change
            // from Strava's byte-level jitter and re-narrate only when it matters.
            $table->string('content_fingerprint', 40)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->dropColumn('content_fingerprint');
        });
    }
};
