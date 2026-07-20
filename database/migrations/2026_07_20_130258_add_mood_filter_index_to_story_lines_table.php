<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serves the Jejak mood filter, which resolves a user's matching activity ids
 * with `where user_id = ? and kind = 'post_run' and mood in (...)`. The existing
 * indexes lead on `(user_id, activity_id)` / `(user_id, for_date)`, so neither
 * covers `kind` + `mood` — without this the filter scans every one of the user's
 * story lines and re-reads each row to test the mood.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->index(['user_id', 'kind', 'mood'], 'story_lines_mood_filter_idx');
        });
    }

    public function down(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->dropIndex('story_lines_mood_filter_idx');
        });
    }
};
