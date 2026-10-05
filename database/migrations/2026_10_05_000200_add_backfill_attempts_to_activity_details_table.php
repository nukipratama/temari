<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->unsignedTinyInteger('weather_attempts')->default(0);
            $table->timestamp('weather_attempted_at')->nullable();
            $table->unsignedTinyInteger('location_attempts')->default(0);
            $table->timestamp('location_attempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropColumn([
                'weather_attempts',
                'weather_attempted_at',
                'location_attempts',
                'location_attempted_at',
            ]);
        });
    }
};
