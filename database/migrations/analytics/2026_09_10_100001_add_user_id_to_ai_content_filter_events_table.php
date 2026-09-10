<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('ai_content_filter_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('ai_content_filter_events', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
