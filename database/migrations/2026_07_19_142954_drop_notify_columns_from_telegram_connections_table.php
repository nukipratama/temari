<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The per-type opt-in now lives on notification_preferences (channel-neutral,
     * governs Telegram + web push alike), backfilled by the preceding migration.
     * These columns are dead once that ships.
     */
    public function up(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'notify_post_run',
                'notify_weekly_recap',
                'notify_monthly_recap',
                'notify_daily_briefing',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->boolean('notify_post_run')->default(true);
            $table->boolean('notify_weekly_recap')->default(true);
            $table->boolean('notify_monthly_recap')->default(true);
            $table->boolean('notify_daily_briefing')->default(false);
        });
    }
};
