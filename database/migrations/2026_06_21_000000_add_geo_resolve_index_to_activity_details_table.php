<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            // Backs the hourly geo:backfill-locations resolve query
            // (start_lat/lng NOT NULL AND location_resolved_at IS NULL). Leads
            // with location_resolved_at: in steady state the IS NULL set is small
            // and shrinking, so MySQL seeks straight to the unresolved backlog
            // instead of full-scanning the table.
            $table->index(
                ['location_resolved_at', 'start_lat', 'start_lng'],
                'activity_details_geo_resolve_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropIndex('activity_details_geo_resolve_idx');
        });
    }
};
