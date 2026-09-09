<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::table('notifications')->where('kind', 'unlock')->delete();

        Schema::dropIfExists('user_unlocks');
    }

    /**
     * Restores the table's shape only. The grants themselves are gone, and so
     * is every class that knew how to recompute them.
     */
    public function down(): void
    {
        Schema::create('user_unlocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('unlock_key', 80);
            $table->timestamp('unlocked_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'unlock_key'], 'user_unlocks_user_key_unique');
        });
    }
};
