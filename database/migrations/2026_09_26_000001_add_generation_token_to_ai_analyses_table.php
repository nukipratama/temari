<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->string('generation_token', 36)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table): void {
            $table->dropColumn('generation_token');
        });
    }
};
