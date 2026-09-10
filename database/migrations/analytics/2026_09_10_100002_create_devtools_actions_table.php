<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the operator actions taken from /devtools. On the `analytics`
 * connection so a `migrate:fresh` of the app database cannot erase who did what.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->create('devtools_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('actor', 64);
            $table->string('action', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('devtools_actions');
    }
};
