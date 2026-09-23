<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('stimulus_adherence_pct')->default(100)->after('adherence_pct');
        });
    }

    public function down(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->dropColumn('stimulus_adherence_pct');
        });
    }
};
