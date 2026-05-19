<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\RunInsightNarrator;
use Override;

class AnalyzeRunInsightJob extends AnalyzeAbstractJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $activity = Activity::query()->with('detail')->find($row->subject_id);
        if ($activity === null || $activity->detail === null) {
            throw new UnavailableException("Activity {$row->subject_id} not analyzed yet");
        }

        $narrator = app(RunInsightNarrator::class);
        $cacheKey = sprintf('run-insight-llm:%d', $activity->id);

        /** @var array{technical: string, splits: string, zones: string} $payload */
        $payload = cache()->remember(
            $cacheKey,
            now()->addMinutes(5),
            fn (): array => $narrator->generate($activity, $activity->detail),
        );

        return match ($row->analysis_type) {
            AnalysisType::RunInsightTechnical => $payload['technical'],
            AnalysisType::RunInsightSplits => $payload['splits'],
            AnalysisType::RunInsightZones => $payload['zones'],
            default => throw new UnavailableException("Unsupported analysis_type for run insight: {$row->analysis_type->value}"),
        };
    }
}
