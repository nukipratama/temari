<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card Reveal trigger flag (Daybreak handoff): when a RunCard is
 * persisted for the first time, RunCardFactory sets this column to
 * the card id. The Inertia middleware surfaces it as `pendingReveal`
 * on every page load; AppShell mounts the 4-frame CardReveal modal
 * when present. POST /api/kartu/{card}/seen clears the flag.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'pending_reveal_card_id')) {
            return;
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('pending_reveal_card_id')
                ->nullable()
                ->after('last_seen_pr_ledger_at')
                ->constrained('run_cards')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pending_reveal_card_id');
        });
    }
};
