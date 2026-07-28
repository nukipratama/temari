<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('telegram_deliveries');
    }

    public function down(): void
    {
        Schema::create('telegram_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->unique()->constrained('ai_analyses')->cascadeOnDelete();
            $table->timestamp('created_at');
        });
    }
};
