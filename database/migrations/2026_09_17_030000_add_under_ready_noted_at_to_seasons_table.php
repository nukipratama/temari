<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->timestamp('under_ready_noted_at')->nullable()->after('opens_with_recovery');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn('under_ready_noted_at');
        });
    }
};
