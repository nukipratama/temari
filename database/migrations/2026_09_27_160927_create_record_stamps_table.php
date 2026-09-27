<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('record_stamps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 5km, 10km, half_marathon, marathon, longest_run
            $table->string('record_key', 30);
            $table->dateTime('seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_stamps');
    }
};
