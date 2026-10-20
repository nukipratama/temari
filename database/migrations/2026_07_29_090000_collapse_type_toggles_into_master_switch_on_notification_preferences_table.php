<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the three per-type flags on the *what* axis into one master switch.
 *
 * The *where* axis (`telegram_enabled`, `push_enabled`) is untouched: the two
 * axes stay independent, which is what stops this becoming a matrix of toggles
 * nobody wants to maintain. This shrinks the *what* axis from three to one, it
 * does not merge it into the channel mutes.
 *
 * Existing rows map by AND, not OR: the master goes on only for a user who had
 * all three on. Any explicit opt-out wins, because the one thing a collapse
 * must never do is turn a notification a user switched off back on. The cost is
 * that a user who kept one type on goes quiet until they flip the switch, which
 * is recoverable in one tap; silently resuming a declined notification is not.
 *
 * The new column defaults true, matching the standing contract that a missing
 * preference row means all-on.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('notifications_enabled')->default(true)->after('user_id');
        });

        DB::table('notification_preferences')->update([
            'notifications_enabled' => DB::raw('post_run AND weekly_recap AND monthly_recap'),
        ]);

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['post_run', 'weekly_recap', 'monthly_recap']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('post_run')->default(true)->after('user_id');
            $table->boolean('weekly_recap')->default(true)->after('post_run');
            $table->boolean('monthly_recap')->default(true)->after('weekly_recap');
        });

        DB::table('notification_preferences')->update([
            'post_run' => DB::raw('notifications_enabled'),
            'weekly_recap' => DB::raw('notifications_enabled'),
            'monthly_recap' => DB::raw('notifications_enabled'),
        ]);

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('notifications_enabled');
        });
    }
};
