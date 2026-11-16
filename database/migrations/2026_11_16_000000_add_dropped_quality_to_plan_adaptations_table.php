<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->json('dropped_quality')->nullable()->after('restorable_quality');
        });

        DB::table('plan_adaptations')->whereNotNull('restorable_quality')->update(['dropped_quality' => DB::raw('restorable_quality')]);
    }

    public function down(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->dropColumn('dropped_quality');
        });
    }
};
