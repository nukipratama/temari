<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** @contract-migration */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn('opens_with_recovery');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->boolean('opens_with_recovery')->default(false)->after('anchor_weekly_volume_km');
        });
    }
};
