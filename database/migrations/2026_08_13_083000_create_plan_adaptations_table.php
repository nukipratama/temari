<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('plan_adaptations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('reason');
            $table->boolean('deload')->default(false);
            $table->integer('quality_delta')->default(0);
            $table->unsignedTinyInteger('adherence_pct')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_adaptations');
    }
};
