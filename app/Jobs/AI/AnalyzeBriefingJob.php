<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\BriefingNarrator;
use Illuminate\Support\Carbon;
use Override;

class AnalyzeBriefingJob extends AnalyzeAbstractJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            throw new UnavailableException("User {$row->subject_id} not found");
        }

        $asOf = $row->discriminator !== null
            ? Carbon::parse($row->discriminator)
            : Carbon::today();

        $narrator = app(BriefingNarrator::class);
        $cacheKey = sprintf('briefing-llm:%d:%s', $user->id, $asOf->toDateString());

        /** @var array{headline: string, suggestion: string} $payload */
        $payload = cache()->remember($cacheKey, now()->addMinutes(5), fn (): array => $narrator->generate($user, $asOf));

        return match ($row->analysis_type) {
            AnalysisType::BriefingHeadline => $payload['headline'],
            AnalysisType::BriefingSuggestion => $payload['suggestion'],
            default => throw new UnavailableException("Unsupported analysis_type for briefing job: {$row->analysis_type->value}"),
        };
    }
}
