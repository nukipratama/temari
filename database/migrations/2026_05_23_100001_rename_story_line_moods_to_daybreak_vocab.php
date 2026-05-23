<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename StoryLine.mood values to the Daybreak vocabulary.
 *
 * Old (legacy PHP enum) → New (Daybreak):
 *   glow      → nyala     (PR / hard win)
 *   bouncy    → enteng    (easy run)
 *   wobble    → lemes     (HR drift → brown-red color slot)
 *   squished  → oleng     (heat strain → amber color slot)
 *   spinning  → mumet     (overreaching / monotony spike)
 *   dim       → adem      (rest day / default)
 */
return new class () extends Migration {
    /** @var array<string, string> */
    private const array MAP = [
        'glow' => 'nyala',
        'bouncy' => 'enteng',
        'wobble' => 'lemes',
        'squished' => 'oleng',
        'spinning' => 'mumet',
        'dim' => 'adem',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('story_lines')->where('mood', $old)->update(['mood' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('story_lines')->where('mood', $new)->update(['mood' => $old]);
        }
    }
};
