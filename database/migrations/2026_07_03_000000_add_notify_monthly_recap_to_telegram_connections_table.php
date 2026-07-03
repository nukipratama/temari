<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->boolean('notify_monthly_recap')->default(true)->after('notify_weekly_recap');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->dropColumn('notify_monthly_recap');
        });
    }
};
