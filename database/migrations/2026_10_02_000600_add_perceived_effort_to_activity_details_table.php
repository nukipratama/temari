<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->unsignedTinyInteger('perceived_effort')->nullable()->after('trimp_edwards');
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropColumn('perceived_effort');
        });
    }
};
