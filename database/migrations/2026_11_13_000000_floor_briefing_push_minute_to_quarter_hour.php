<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A briefing send time is now a quarter hour. Each saved time is floored to the
 * quarter hour the sweep already sent it on, so no athlete's push moves.
 *
 * down() is a no-op: the dropped minutes never changed when the push went out.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('notification_preferences')
            ->whereNotNull('briefing_push_minute')
            ->update(['briefing_push_minute' => DB::raw('briefing_push_minute - MOD(briefing_push_minute, 15)')]);
    }

    public function down(): void
    {
    }
};
