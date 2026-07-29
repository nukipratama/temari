<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Narrators\BriefingNarrator;

/**
 * Standalone row job for the daily briefing suggestion, the sibling of
 * {@see AnalyzeBriefingMascotVoiceJob} and
 * {@see AnalyzeBriefingFeaturedKartuVoiceJob}: the three briefing surfaces share
 * a subject type but each retries without re-spending LLM tokens on the others.
 */
class AnalyzeBriefingJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            throw new UnavailableException("User {$row->subject_id} not found");
        }

        return app(BriefingNarrator::class)->generate($user, $this->discriminatorDate($row));
    }
}
