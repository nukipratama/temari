<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('race_goals', function (Blueprint $table): void {
            $table->timestamp('reminded_at')->nullable()->after('outcome_recorded_at');
            $table->timestamp('outcome_asked_at')->nullable()->after('reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('race_goals', function (Blueprint $table): void {
            $table->dropColumn(['reminded_at', 'outcome_asked_at']);
        });
    }
};
