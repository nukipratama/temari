<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename RunCard.badges slug values from Bahasa to English keys, matching
 * the App\Enums\Badge case values. `badges` is a JSON array column, so each
 * row is decoded, its matching slugs replaced, then re-encoded — a plain
 * `WHERE badges = ...` update cannot target values inside a JSON array.
 *
 *   hari_panas    → heat_tamer
 *   pejuang_hujan → rain_warrior
 *   anak_pagi     → early_bird
 *   tahan_diri    → held_back
 *   anak_malam    → night_owl
 *   pendaki       → climber
 *   pertama_kali  → first_timer
 *   jauh          → long_hauler
 *   anak_dingin   → cold_runner
 *   keras         → all_out
 *   santai        → easy_miles
 *   berturut      → streak
 *   hari_spesial  → holiday_run
 *   lawan_angin   → headwind
 *   rajin         → habit_forming
 *   kilat         → speedster
 *
 * (long_slow_distance, negative_split, z2_master were already English.)
 */
return new class () extends Migration {
    /** @var array<string, string> */
    private const array MAP = [
        'hari_panas' => 'heat_tamer',
        'pejuang_hujan' => 'rain_warrior',
        'anak_pagi' => 'early_bird',
        'tahan_diri' => 'held_back',
        'anak_malam' => 'night_owl',
        'pendaki' => 'climber',
        'pertama_kali' => 'first_timer',
        'jauh' => 'long_hauler',
        'anak_dingin' => 'cold_runner',
        'keras' => 'all_out',
        'santai' => 'easy_miles',
        'berturut' => 'streak',
        'hari_spesial' => 'holiday_run',
        'lawan_angin' => 'headwind',
        'rajin' => 'habit_forming',
        'kilat' => 'speedster',
    ];

    public function up(): void
    {
        $this->replace(self::MAP);
    }

    public function down(): void
    {
        $this->replace(array_flip(self::MAP));
    }

    /**
     * @param  array<string, string>  $map
     */
    private function replace(array $map): void
    {
        DB::table('run_cards')
            ->whereNotNull('badges')
            ->select(['id', 'badges'])
            ->chunkById(500, function ($rows) use ($map): void {
                foreach ($rows as $row) {
                    /** @var list<string>|null $badges */
                    $badges = json_decode((string) $row->badges, true);

                    if (! is_array($badges)) {
                        continue;
                    }

                    $renamed = array_map(
                        fn (string $badge): string => $map[$badge] ?? $badge,
                        $badges,
                    );

                    if ($renamed === $badges) {
                        continue;
                    }

                    DB::table('run_cards')
                        ->where('id', $row->id)
                        ->update(['badges' => json_encode(array_values($renamed))]);
                }
            });
    }
};
