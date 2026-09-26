<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $duplicateIds = DB::table('season_goals')
            ->whereNotIn('id', DB::table('season_goals')->selectRaw('MIN(id)')->groupBy('season_id', 'metric'))
            ->pluck('id');

        DB::table('season_goals')->whereIn('id', $duplicateIds)->delete();

        Schema::table('season_goals', function (Blueprint $table): void {
            $table->unique(['season_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::table('season_goals', function (Blueprint $table): void {
            $table->dropUnique(['season_id', 'metric']);
        });
    }
};
