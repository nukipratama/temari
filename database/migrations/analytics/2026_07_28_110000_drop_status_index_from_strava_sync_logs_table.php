<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('strava_sync_logs', function (Blueprint $table): void {
            $table->dropIndex('strava_sync_logs_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('strava_sync_logs', function (Blueprint $table): void {
            $table->index('status');
        });
    }
};
