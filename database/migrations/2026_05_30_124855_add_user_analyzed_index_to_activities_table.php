<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Composite index for the hot "this user's analyzed runs" filter
     * (`WHERE user_id = ? AND analyzed_at IS NOT NULL`) that the calendar,
     * rekor and dashboard pages all run. The standalone `analyzed_at` index
     * and the `user_id` FK index can't serve that combined predicate as
     * efficiently as a composite leading with `user_id`.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->index(['user_id', 'analyzed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'analyzed_at']);
        });
    }
};
