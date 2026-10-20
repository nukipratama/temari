<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `PlanNarrationRequester` used to request a `plan_day_voice` row for all seven
 * days of the week even where no `PlannedSession` existed, which stranded rows
 * on days outside the athlete's plan. The requester now skips those days, but
 * the rows it already wrote stayed Failed forever: nothing can fill them, so
 * every self-heal sweep retried them and /ai-usage showed the athlete a block
 * "still auto-retrying" behind a Try again that could never succeed.
 *
 * Scoped by the condition itself rather than by id, so it clears the rows on
 * any environment without assuming which ones exist where.
 *
 * down() is a documented no-op: these rows carry no content to restore, and
 * recreating them would recreate the stuck state.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('ai_analyses')
            ->where('analysis_type', 'plan_day_voice')
            ->whereNotNull('discriminator')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('planned_sessions')
                ->whereColumn('planned_sessions.user_id', 'ai_analyses.subject_id')
                ->whereColumn('planned_sessions.date', 'ai_analyses.discriminator'))
            ->delete();
    }

    public function down(): void
    {
        // Deliberately irreversible: the rows describe days that have no
        // planned session, so recreating them would recreate the stuck state.
    }
};
