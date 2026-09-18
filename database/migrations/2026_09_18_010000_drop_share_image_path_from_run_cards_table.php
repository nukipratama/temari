<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('run_cards', function (Blueprint $table): void {
            $table->dropColumn('share_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('run_cards', function (Blueprint $table): void {
            $table->string('share_image_path')->nullable();
        });
    }
};
