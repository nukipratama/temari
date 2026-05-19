<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Services\AI\Narrators\PrContextNarrator;
use Override;

class AnalyzePrContextJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $pr = PersonalRecord::query()->find($row->subject_id);
        if ($pr === null) {
            throw new UnavailableException("PersonalRecord {$row->subject_id} not found");
        }

        return app(PrContextNarrator::class)->generate($pr);
    }
}
