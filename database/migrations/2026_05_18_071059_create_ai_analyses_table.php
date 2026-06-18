<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Dev environments that ran the pre-rename migration have a stale
        // `llm_analyses` table sitting next to nothing. Drop it before the
        // create so we don't leave two tables behind.
        Schema::dropIfExists('llm_analyses');

        Schema::create('ai_analyses', function (Blueprint $table): void {
            $table->id();
            // Polymorphic subject. Usually a Model (Activity, PersonalRecord, etc.).
            // Composite subjects (e.g. "User-Day" for daily briefing) use sentinel
            // strings as subject_type with subject_id = user_id, and `discriminator`
            // holds the per-day key (ISO date) so each day gets its own row.
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('analysis_type', 60);
            $table->string('discriminator', 40)->nullable();
            // MySQL treats each NULL as distinct in a unique index, so rows with
            // a NULL discriminator (per-activity, weekly) would escape the unique
            // constraint and race into duplicates. Fold NULL to '' in a stored
            // generated column and key the unique index on that instead.
            $table->string('discriminator_key', 40)->storedAs("coalesce(discriminator, '')");
            $table->string('status', 16)->default('pending');
            $table->mediumText('content')->nullable();
            $table->text('error')->nullable();
            $table->string('model_version', 80)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'analysis_type', 'discriminator_key'],
                'ai_analyses_subject_type_disc_unique',
            );
            $table->index(['status', 'updated_at'], 'ai_analyses_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
    }
};
