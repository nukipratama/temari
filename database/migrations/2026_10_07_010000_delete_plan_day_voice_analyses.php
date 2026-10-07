<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The per-day "Temari's read" (`plan_day_voice`) was removed with no
 * replacement, so its stored rows and the runner flags on them go too. A flag
 * on a narration is a `narration` feedback row keyed by the analysis id, with
 * no foreign key, so it is deleted first; the rows' versions and notification
 * deliveries cascade. The cost history in `ai_token_usages` on the analytics
 * connection is kept.
 *
 * down() is a documented no-op: the deleted narration text cannot be restored.
 */
return new class () extends Migration {
    /** @contract-migration */
    public function up(): void
    {
        $dayReads = DB::table('ai_analyses')->where('analysis_type', 'plan_day_voice')->select('id');

        DB::table('feedback')
            ->where('subject_type', 'narration')
            ->whereIn('subject_id', $dayReads)
            ->delete();

        DB::table('ai_analyses')->where('analysis_type', 'plan_day_voice')->delete();
    }

    public function down(): void
    {
    }
};
