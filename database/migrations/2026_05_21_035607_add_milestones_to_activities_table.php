<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * `milestones_detected_at` flips once the post-ingest detector runs so
     * we never re-fire the banner on re-sync. `milestone_payload` is the
     * cached milestone list — null when dismissed.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->timestamp('milestones_detected_at')->nullable()->after('analyzed_at');
            $table->json('milestone_payload')->nullable()->after('milestones_detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn(['milestones_detected_at', 'milestone_payload']);
        });
    }
};
