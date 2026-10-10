<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** @contract-migration */
    public function up(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->dropColumn('sigil_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('story_lines', function (Blueprint $table): void {
            $table->string('sigil_pattern', 40)->nullable()->after('speech');
        });
    }
};
