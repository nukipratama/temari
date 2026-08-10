<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename StoryLine.mood values to the Daybreak vocabulary.
 *
 * Old (legacy PHP enum) → New (Daybreak):
 *   glow      → blazing     (PR / hard win)
 *   bouncy    → easy    (easy run)
 *   wobble    → gassed     (HR drift → brown-red color slot)
 *   squished  → wobbly     (heat strain → amber color slot)
 *   spinning  → overloaded     (overreaching / monotony spike)
 *   dim       → chill      (rest day / default)
 */
return new class () extends Migration {
    /** @var array<string, string> */
    private const array MAP = [
        'glow' => 'blazing',
        'bouncy' => 'easy',
        'wobble' => 'gassed',
        'squished' => 'wobbly',
        'spinning' => 'overloaded',
        'dim' => 'chill',
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
