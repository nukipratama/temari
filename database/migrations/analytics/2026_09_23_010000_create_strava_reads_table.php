<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->create('strava_reads', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('read_at');
            $table->string('source', 32);
            $table->string('priority', 16);
            $table->string('endpoint', 32);
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('usage_15m')->nullable();
            $table->unsignedInteger('usage_daily')->nullable();

            $table->index(['read_at', 'source', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('strava_reads');
    }
};
