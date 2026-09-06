<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('backfilled_at')->nullable()->after('onboarded_at');
        });

        // Rows that predate the marker stay null: nothing observed their
        // backfill landing, and no behaviour keyed on it ran for them.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('backfilled_at');
        });
    }
};
