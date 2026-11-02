<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->json('deferred_time_trial')->nullable()->after('increases_held');
        });
    }

    public function down(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->dropColumn('deferred_time_trial');
        });
    }
};
