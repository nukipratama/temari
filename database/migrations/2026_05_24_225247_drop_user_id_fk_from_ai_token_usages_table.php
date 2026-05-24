<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Drop the FK so deleting a user does NOT null `ai_token_usages.user_id`.
     *
     * Keeping the bare integer preserves the per-user breakdown even after the
     * user row is gone — the AI usage dashboard falls back to "User #<id>"
     * instead of bucketing everything into an unknown "tanpa user" row, which
     * conflated truly system-context calls with deleted-user history.
     */
    public function up(): void
    {
        Schema::table('ai_token_usages', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_token_usages', function (Blueprint $table): void {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
