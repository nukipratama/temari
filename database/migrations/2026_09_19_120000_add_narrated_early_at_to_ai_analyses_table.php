<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->timestamp('narrated_early_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->dropColumn('narrated_early_at');
        });
    }
};
