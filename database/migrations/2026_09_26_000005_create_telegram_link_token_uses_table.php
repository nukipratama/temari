<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('telegram_link_token_uses', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_token_uses');
    }
};
