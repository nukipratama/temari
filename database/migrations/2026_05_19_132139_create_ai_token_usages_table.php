<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_token_usages', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 64);
            $table->unsignedInteger('prompt_tokens');
            $table->unsignedInteger('completion_tokens');
            $table->unsignedInteger('total_tokens');
            $table->string('model', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Date-range filter + GROUP BY kind on the dashboard query.
            $table->index(['created_at', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_usages');
    }
};
