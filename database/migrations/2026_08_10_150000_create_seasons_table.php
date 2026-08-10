<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_goal_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->timestamps();

            $table->unique(['user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
