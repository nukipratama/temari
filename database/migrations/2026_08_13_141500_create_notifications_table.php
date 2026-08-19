<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The durable inbox, distinct from notification_deliveries (which is
        // per-(analysis, channel) outbound bookkeeping, not a user-facing record).
        // dedupe_key is unique per user so a queued retry, or a second markDone
        // for the same analysis, updates nothing rather than stacking a duplicate.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('dedupe_key', 191);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
