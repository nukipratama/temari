<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('streak_rest_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('earned_for_week_ending');
            $table->date('spent_for_week_ending')->nullable();
            $table->timestamps();

            // One token minted per user per week, so a re-run of the settle
            // command cannot mint a second, and a rebuilt streak can earn again.
            $table->unique(['user_id', 'earned_for_week_ending']);
            // One token spent per forgiven week. MySQL permits many NULLs here,
            // which is what keeps unspent tokens stackable.
            $table->unique(['user_id', 'spent_for_week_ending']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streak_rest_tokens');
    }
};
