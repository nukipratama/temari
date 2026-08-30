<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\Season;
use App\Services\AI\Narrators\PlanSeasonVoiceNarrator;

class AnalyzePlanSeasonVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $season = Season::query()->find($row->subject_id);
        if ($season === null) {
            throw new UnavailableException("Season {$row->subject_id} not found");
        }

        return app(PlanSeasonVoiceNarrator::class)->generate($season);
    }
}
