<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aksesori dress-up surface (Daybreak handoff): one `equipped` flag
 * per unlock row. The server enforces "one equipped per slot" by
 * un-equipping siblings whose unlock_key shares the slot prefix
 * (headband_* / medali_* / pita_* / aura_*) on equip.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('user_unlocks', 'equipped')) {
            return;
        }
        Schema::table('user_unlocks', function (Blueprint $table): void {
            $table->boolean('equipped')->default(false)->after('unlock_key');
        });
    }

    public function down(): void
    {
        Schema::table('user_unlocks', function (Blueprint $table): void {
            $table->dropColumn('equipped');
        });
    }
};
