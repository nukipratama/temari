<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->decimal('volume_floor_km', 6, 2)->nullable()->after('anchor_weekly_volume_km');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn('volume_floor_km');
        });
    }
};
