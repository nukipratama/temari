<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('post_run')->default(true);
            $table->boolean('weekly_recap')->default(true);
            $table->boolean('monthly_recap')->default(true);
            $table->timestamps();
        });

        // Carry each existing user's per-type choices over from their Telegram
        // connection (the daily-briefing toggle is intentionally dropped). New
        // channel-neutral home; the notify_* columns are retired in the next
        // migration. A user with no connection keeps the all-on default (no row).
        $now = now();
        DB::table('telegram_connections')->orderBy('id')->chunkById(500, function ($connections) use ($now): void {
            $rows = $connections->map(fn ($connection): array => [
                'user_id' => $connection->user_id,
                'post_run' => $connection->notify_post_run,
                'weekly_recap' => $connection->notify_weekly_recap,
                'monthly_recap' => $connection->notify_monthly_recap,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('notification_preferences')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
