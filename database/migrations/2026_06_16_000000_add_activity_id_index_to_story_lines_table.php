<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Standalone `activity_id` index for the hot run-detail, dashboard,
     * calendar and activity-list queries that filter by `activity_id`
     * without a leading `user_id`. The composite `unique(['user_id',
     * 'activity_id'])` can't serve those as a left-prefix, so they
     * table-scan without this index.
     */
    public function up(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->index('activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->dropIndex(['activity_id']);
        });
    }
};
