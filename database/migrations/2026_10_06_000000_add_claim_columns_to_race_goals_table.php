<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('race_goals', function (Blueprint $table): void {
            $table->date('reminded_for_date')->nullable()->after('outcome_recorded_at');
            $table->date('outcome_asked_for_date')->nullable()->after('reminded_for_date');
        });
    }

    public function down(): void
    {
        Schema::table('race_goals', function (Blueprint $table): void {
            $table->dropColumn(['reminded_for_date', 'outcome_asked_for_date']);
        });
    }
};
