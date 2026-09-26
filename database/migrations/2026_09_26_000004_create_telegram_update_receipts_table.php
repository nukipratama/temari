<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('telegram_update_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_update_receipts');
    }
};
