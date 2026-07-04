<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            // Defaults false, unlike the other three: a daily push is a higher-commitment
            // opt-in, so existing users aren't auto-enrolled into a new daily notification.
            $table->boolean('notify_daily_briefing')->default(false)->after('notify_monthly_recap');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->dropColumn('notify_daily_briefing');
        });
    }
};
