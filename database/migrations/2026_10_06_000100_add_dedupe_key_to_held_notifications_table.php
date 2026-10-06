<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('held_notifications', function (Blueprint $table): void {
            $table->string('dedupe_key')->nullable()->unique()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('held_notifications', function (Blueprint $table): void {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
