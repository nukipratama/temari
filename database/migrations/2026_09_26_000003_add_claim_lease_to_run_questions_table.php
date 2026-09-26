<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('run_questions', function (Blueprint $table): void {
            $table->string('claim_token', 36)->nullable()->after('status');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
        });
    }

    public function down(): void
    {
        Schema::table('run_questions', function (Blueprint $table): void {
            $table->dropColumn(['claim_token', 'claimed_at']);
        });
    }
};
