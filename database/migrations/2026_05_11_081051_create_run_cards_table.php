<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('run_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();
            // biasa, jarang, langka, epik, legendaris
            $table->string('rarity', 20);
            // ["hari_panas", "negative_split", ...]
            $table->json('badges');
            // "Paru-paru Baja", "Metronom", etc.
            $table->string('special_move', 60);
            // null until the share image is generated on first request
            $table->string('share_image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_cards');
    }
};
