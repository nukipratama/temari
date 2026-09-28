<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('weekly_snapshots', function (Blueprint $table): void {
            $table->double('avg_decoupling_v2')->nullable()->after('avg_decoupling');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_snapshots', function (Blueprint $table): void {
            $table->dropColumn('avg_decoupling_v2');
        });
    }
};
