<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Services\AI\Narrators\PlanWeekVoiceNarrator;

class AnalyzePlanWeekVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $adaptation = PlanAdaptation::query()->find($row->subject_id);
        if ($adaptation === null) {
            throw new UnavailableException("PlanAdaptation {$row->subject_id} not found");
        }

        return app(PlanWeekVoiceNarrator::class)->generate($adaptation);
    }
}
