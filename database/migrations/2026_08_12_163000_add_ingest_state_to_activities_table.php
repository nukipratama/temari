<?php

declare(strict_types=1);

use App\Enums\IngestState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('ingest_state', 16)
                ->default(IngestState::Summary->value)
                ->after('strava_external_id');

            $table->index(['user_id', 'ingest_state']);
        });

        DB::table('activities')
            ->whereNotNull('analyzed_at')
            ->update(['ingest_state' => IngestState::Detailed->value]);
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'ingest_state']);
            $table->dropColumn('ingest_state');
        });
    }
};
