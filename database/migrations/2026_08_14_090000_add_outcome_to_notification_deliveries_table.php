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
            $table->string('status', 16)->default('pending')->after('channel');
            $table->text('error')->nullable()->after('status');
            $table->timestamp('settled_at')->nullable()->after('created_at');
            $table->index(['status', 'created_at']);
        });

        // Every pre-existing row is a claim that was held to completion, since
        // the old release() deleted the row on failure.
        DB::table('notification_deliveries')->update([
            'status' => 'sent',
            'settled_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'error', 'settled_at']);
        });
    }
};
