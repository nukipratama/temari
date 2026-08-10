<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename UserUnlock.unlock_key values from Bahasa to English, matching the
 * catalog keys in config/temari_unlocks.php and config/temari_goals.php.
 * Slot names embedded in each key move too: ikat_kepala → headband,
 * kaus → shirt, celana → shorts, sepatu → shoes (medal and aura unchanged).
 */
return new class () extends Migration {
    /** @var array<string, string> */
    private const array MAP = [
        'accessory.medal_pertama' => 'accessory.medal_first',
        'accessory.medal_emas' => 'accessory.medal_gold',
        'accessory.medal_perak' => 'accessory.medal_silver',
        'accessory.medal_platina' => 'accessory.medal_platinum',
        'accessory.ikat_kepala_berkesan' => 'accessory.headband_uncommon',
        'accessory.ikat_kepala_langka' => 'accessory.headband_rare',
        'accessory.ikat_kepala_epik' => 'accessory.headband_epic',
        'accessory.ikat_kepala_legendaris' => 'accessory.headband_legendary',
        'accessory.kaus_pemula' => 'accessory.shirt_beginner',
        'accessory.kaus_pagi' => 'accessory.shirt_early_bird',
        'accessory.kaus_hujan' => 'accessory.shirt_rain_warrior',
        'accessory.kaus_legendaris' => 'accessory.shirt_legendary',
        'accessory.celana_ringan' => 'accessory.shorts_lightweight',
        'accessory.celana_jarak' => 'accessory.shorts_explorer',
        'accessory.celana_split' => 'accessory.shorts_negative_split',
        'accessory.celana_maraton' => 'accessory.shorts_marathon',
        'accessory.sepatu_basic' => 'accessory.shoes_basic',
        'accessory.sepatu_cepat' => 'accessory.shoes_speed',
        'accessory.sepatu_tahan' => 'accessory.shoes_rugged',
        'accessory.sepatu_legendaris' => 'accessory.shoes_legendary',
        'accessory.aura_pemanasan' => 'accessory.aura_warmup',
        'accessory.aura_gerah' => 'accessory.aura_heatwave',
        'accessory.aura_tenang' => 'accessory.aura_calm',
        'accessory.aura_jagoan' => 'accessory.aura_champion',
        'accessory.aura_angin' => 'accessory.aura_windrunner',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('user_unlocks')->where('unlock_key', $old)->update(['unlock_key' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('user_unlocks')->where('unlock_key', $new)->update(['unlock_key' => $old]);
        }
    }
};
