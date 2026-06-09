<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Durable runtime control plane: kill-switches + circuit-breaker state.
        // Lives on the default connection (shared by app/horizon/scheduler) so a
        // toggle is visible everywhere immediately, and survives restarts / Redis
        // LRU eviction. Defaults live in code (AppConfigKey); a row only exists
        // once a value is overridden at runtime.
        Schema::create('app_config', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_config');
    }
};
