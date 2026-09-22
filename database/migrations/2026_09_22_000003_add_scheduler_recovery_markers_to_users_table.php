<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('streak_settled_through')->nullable()->after('trend_snapshots_rebuilding_from');
            $table->date('trend_snapshots_scheduled_through')->nullable()->after('streak_settled_through');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['streak_settled_through', 'trend_snapshots_scheduled_through']);
        });
    }
};
