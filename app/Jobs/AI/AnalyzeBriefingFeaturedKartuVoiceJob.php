<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Narrators\BriefingFeaturedKartuVoiceNarrator;

/**
 * Standalone row job for the "Kata Temari" quote on the Featured Kartu panel.
 * Split from {@see AnalyzeBriefingMascotVoiceJob} so retrying one surface
 * doesn't also re-spend LLM tokens on the other.
 */
class AnalyzeBriefingFeaturedKartuVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->findOrFail($row->subject_id);
        $asOf = $this->discriminatorDate($row);

        return app(BriefingFeaturedKartuVoiceNarrator::class)->generate($user, $asOf);
    }
}
