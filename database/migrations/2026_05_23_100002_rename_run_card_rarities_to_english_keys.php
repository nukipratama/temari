<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename RunCard.rarity values from Bahasa to English keys per the
 * Daybreak handoff. UI strings stay Bahasa via the RunCard::RARITY_LABELS
 * presenter map (en-key → ID-label).
 *
 *   biasa       → common
 *   jarang      → uncommon
 *   langka      → rare
 *   epik        → epic
 *   legendaris  → legendary
 */
return new class () extends Migration {
    /** @var array<string, string> */
    private const array MAP = [
        'biasa' => 'common',
        'jarang' => 'uncommon',
        'langka' => 'rare',
        'epik' => 'epic',
        'legendaris' => 'legendary',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('run_cards')->where('rarity', $old)->update(['rarity' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('run_cards')->where('rarity', $new)->update(['rarity' => $old]);
        }
    }
};
