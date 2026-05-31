<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AI\Analysis;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Models\User;

/**
 * Standalone row job for the "Kata Temari hari ini" mascot-voice line.
 * Split from {@see AnalyzeBriefingJob} so retrying this surface doesn't
 * also re-spend LLM tokens on the briefing headline + suggestion.
 */
class AnalyzeBriefingMascotVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->findOrFail($row->subject_id);
        $asOf = $this->discriminatorDate($row);

        return app(BriefingMascotVoiceNarrator::class)->generate($user, $asOf);
    }
}
