<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fitness_anchors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('vdot', 5, 1);
            $table->decimal('quality_vdot', 5, 1);
            $table->unsignedBigInteger('source_activity_id')->nullable();
            $table->string('source_category');
            $table->decimal('source_value_sec', 10, 2);
            $table->date('set_at');
            $table->unsignedBigInteger('quality_source_activity_id')->nullable();
            $table->string('quality_source_category')->nullable();
            $table->decimal('quality_source_value_sec', 10, 2)->nullable();
            $table->date('quality_set_at')->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_anchors');
    }
};
