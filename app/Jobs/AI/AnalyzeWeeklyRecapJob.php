<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use Override;

class AnalyzeWeeklyRecapJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $snapshot = WeeklySnapshot::query()->find($row->subject_id);
        if ($snapshot === null) {
            throw new UnavailableException("WeeklySnapshot {$row->subject_id} not found");
        }

        return app(WeeklyRecapNarrator::class)->generate($snapshot);
    }
}
