<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('intervals_icu_connections', function (Blueprint $table): void {
            $table->date('synced_on')->nullable()->after('broken_at');
            $table->string('sync_status', 32)->nullable()->after('synced_on');
            $table->string('event_id', 32)->nullable()->after('sync_status');
            $table->string('payload_hash', 64)->nullable()->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('intervals_icu_connections', function (Blueprint $table): void {
            $table->dropColumn(['synced_on', 'sync_status', 'event_id', 'payload_hash']);
        });
    }
};
