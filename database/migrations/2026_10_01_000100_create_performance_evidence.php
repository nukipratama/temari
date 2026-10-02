<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('performance_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('race_goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('elapsed_time_sec');
            $table->date('performed_on');
            $table->dateTime('confirmed_at');
            $table->timestamps();
            $table->unique(['user_id', 'activity_id']);
            $table->index(['user_id', 'performed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_evidence');
    }
};
