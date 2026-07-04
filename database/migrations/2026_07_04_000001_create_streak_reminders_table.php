<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One row per user per at-risk week: the unique (user_id, week_ending)
        // pair is the idempotency claim so a re-run of streak:remind within the
        // same week never double-pushes the nudge.
        Schema::create('streak_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_ending');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'week_ending']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streak_reminders');
    }
};
