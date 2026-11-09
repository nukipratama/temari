<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The run insight is retired, so its rows that never finished are deleted
 * along with the runner flags on them; done rows stay for old runs. A flag on
 * a narration is a `narration` feedback row keyed by the analysis id, with no
 * foreign key, so it is deleted first; the rows' versions and notification
 * deliveries cascade. The cost history in `ai_token_usages` on the analytics
 * connection is kept.
 *
 * down() is a documented no-op: the deleted narration text cannot be restored.
 */
return new class () extends Migration {
    private const int CHUNK = 500;

    /** @contract-migration */
    public function up(): void
    {
        do {
            $ids = DB::table('ai_analyses')
                ->where('analysis_type', 'run_insight')
                ->where('status', '!=', 'done')
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            DB::table('feedback')
                ->where('subject_type', 'narration')
                ->whereIn('subject_id', $ids)
                ->delete();

            DB::table('ai_analyses')->whereIn('id', $ids)->delete();
        } while (count($ids) === self::CHUNK);
    }

    public function down(): void
    {
    }
};
