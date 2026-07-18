<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One row per (analysis, channel): the unique (analysis_id, channel) pair
        // is the idempotency claim so a queued retry — or a re-run of markDone for
        // the same analysis — never double-sends on that channel. Generalises
        // telegram_deliveries so web push shares the same mechanism.
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained('ai_analyses')->cascadeOnDelete();
            $table->string('channel');
            $table->timestamp('created_at');
            $table->unique(['analysis_id', 'channel']);
        });

        // Carry existing Telegram claims over so an in-flight analysis isn't
        // re-notified across the deploy. telegram_deliveries is left in place (now
        // unused) and dropped in a later migration once no old code writes to it.
        DB::table('notification_deliveries')->insertUsing(
            ['analysis_id', 'channel', 'created_at'],
            DB::table('telegram_deliveries')->selectRaw("analysis_id, 'telegram' as channel, created_at"),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
