<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The briefing push's own switch, under the master switch, and an optional send
 * time in minutes since local midnight; null means the automatic time.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('briefing_push_enabled')->default(true)->after('notifications_enabled');
            $table->unsignedSmallInteger('briefing_push_minute')->nullable()->after('briefing_push_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['briefing_push_enabled', 'briefing_push_minute']);
        });
    }
};
