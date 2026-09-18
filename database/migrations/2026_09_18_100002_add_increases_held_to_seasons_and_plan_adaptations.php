<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->boolean('increases_held')->default(false)->after('volume_floor_km');
        });
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->boolean('increases_held')->default(false)->after('volume_floor_km');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn('increases_held');
        });
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->dropColumn('increases_held');
        });
    }
};
