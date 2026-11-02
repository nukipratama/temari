<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->smallInteger('weather_temp_max_c')->nullable()->after('weather_temp_c');
            $table->smallInteger('weather_dew_point_c')->nullable()->after('weather_humidity_pct');
            $table->smallInteger('weather_apparent_temp_c')->nullable()->after('weather_dew_point_c');
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropColumn([
                'weather_temp_max_c',
                'weather_dew_point_c',
                'weather_apparent_temp_c',
            ]);
        });
    }
};
