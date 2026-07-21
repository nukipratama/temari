<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the second, orthogonal axis to notification preferences.
 *
 * The three existing flags answer *what* gets sent and stay channel-neutral.
 * These answer *where* it may go. Keeping the two axes independent is what
 * stops this becoming a 3x2 matrix of toggles nobody wants to maintain.
 *
 * Both default true, matching the existing contract that a missing preference
 * row means all-on: adding the columns must not mute anyone.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('telegram_enabled')->default(true)->after('monthly_recap');
            $table->boolean('push_enabled')->default(true)->after('telegram_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['telegram_enabled', 'push_enabled']);
        });
    }
};
