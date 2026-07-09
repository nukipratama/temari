<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table): void {
            $table->string('source')->default('default')->after('user_id');
            $table->timestamp('strava_zones_synced_at')->nullable()->after('hr_zones_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table): void {
            $table->dropColumn(['source', 'strava_zones_synced_at']);
        });
    }
};
