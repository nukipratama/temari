<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('recommendation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('fingerprint', 64);
            $table->unsignedInteger('policy_version');
            $table->json('original');
            $table->json('effective');
            $table->dateTime('created_at', 6);
            $table->unique(['user_id', 'date', 'fingerprint'], 'recommendation_revision_identity');
        });
        Schema::create('recommendation_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recommendation_revision_id')->constrained()->cascadeOnDelete();
            $table->uuid('observation_id')->unique();
            $table->dateTime('shown_at', 6)->index();
        });
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dateTime('start_date_utc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropColumn('start_date_utc');
        });
        Schema::dropIfExists('recommendation_views');
        Schema::dropIfExists('recommendation_revisions');
    }
};
