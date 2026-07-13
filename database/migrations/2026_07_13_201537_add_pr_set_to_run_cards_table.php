<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('run_cards', function (Blueprint $table): void {
            // Sticky record of whether this card was minted off a personal record,
            // so a later run beating that PR never retroactively downgrades it.
            $table->boolean('pr_set')->default(false)->after('special_move');
        });
    }

    public function down(): void
    {
        Schema::table('run_cards', function (Blueprint $table): void {
            $table->dropColumn('pr_set');
        });
    }
};
