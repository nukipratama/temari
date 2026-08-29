<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('training_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('experience_level')->nullable();
            $table->unsignedTinyInteger('sessions_per_week')->nullable();
            $table->string('goal_type')->nullable();
            $table->json('run_days')->nullable();
            $table->unsignedTinyInteger('long_run_day')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_preferences');
    }
};
