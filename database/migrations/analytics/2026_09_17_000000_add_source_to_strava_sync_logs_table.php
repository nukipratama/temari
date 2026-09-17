<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('strava_sync_logs', function (Blueprint $table): void {
            $table->string('source', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('strava_sync_logs', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
