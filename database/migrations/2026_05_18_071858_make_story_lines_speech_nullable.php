<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->text('speech')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->text('speech')->nullable(false)->change();
        });
    }
};
