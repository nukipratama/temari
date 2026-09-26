<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->after('created_at');
            $table->unsignedInteger('claim_version')->default(0)->after('claimed_at');
            $table->index(['status', 'claimed_at'], 'notification_deliveries_stale_claims_index');
        });

        DB::table('notification_deliveries')
            ->where('status', 'pending')
            ->update([
                'claimed_at' => DB::raw('created_at'),
                'claim_version' => 1,
            ]);
    }

    public function down(): void
    {
        DB::table('notification_deliveries')
            ->where('status', 'abandoned')
            ->update([
                'status' => 'sent',
                'error' => 'Delivery outcome was unknown at rollback; automatic retries stay suppressed.',
            ]);

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('notification_deliveries_stale_claims_index');
            $table->dropColumn(['claimed_at', 'claim_version']);
        });
    }
};
