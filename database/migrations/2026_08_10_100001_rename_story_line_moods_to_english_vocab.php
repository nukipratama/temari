<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename StoryLine.mood values from the Bahasa Daybreak vocabulary to English,
 * matching Temari::MOOD_* ({@see app/Services/Run/Story/Temari.php}).
 *
 *   nyala  → blazing   (PR / hard win)
 *   enteng → easy       (easy run / negative split)
 *   oleng  → wobbly     (HR drift / heat strain)
 *   lemes  → gassed     (wobble / decoupling drift)
 *   mumet  → overloaded (overreaching / hard-zone heavy)
 *   adem   → chill      (rest day / default)
 */
return new class () extends Migration {
    /** @var array<string, string> */
    private const array MAP = [
        'nyala' => 'blazing',
        'enteng' => 'easy',
        'oleng' => 'wobbly',
        'lemes' => 'gassed',
        'mumet' => 'overloaded',
        'adem' => 'chill',
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
