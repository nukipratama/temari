<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AnalysisType::RunInsightTechnical/Splits/Zones were consolidated into one
 * RunInsight case, which left every prior row of those three retired types
 * behind under their old string values with no live enum case to resolve
 * them to. `run_insight`'s content shape (a variable-length
 * `{claims: [...]}` list) also does not match what the three old lenses
 * stored, so the rows cannot be rewritten onto the new type; they are simply
 * dead weight now excluded from every query by
 * {@see \App\Models\Scopes\KnownAnalysisTypeScope}.
 *
 * down() is a documented no-op: deleted narration content is not
 * reconstructable, and these are the exact same dead-weight rows either way.
 */
return new class () extends Migration {
    private const array RETIRED_TYPES = [
        'run_insight_technical',
        'run_insight_splits',
        'run_insight_zones',
    ];

    public function up(): void
    {
        DB::table('ai_analyses')->whereIn('analysis_type', self::RETIRED_TYPES)->delete();
    }

    public function down(): void
    {
        // Deliberately irreversible: the deleted narration content cannot be
        // reconstructed, and re-inserting empty placeholder rows would just
        // recreate the same orphaned dead weight this migration removes.
    }
};
