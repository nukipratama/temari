<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\BriefingNarrator;
use Illuminate\Support\Carbon;
use Override;

class AnalyzeBriefingJob extends AnalyzeGroupJob
{
    #[Override]
    public static function groupedTypes(): array
    {
        return AnalysisType::groupedBy(self::class);
    }

    #[Override]
    public static function subjectType(): string
    {
        return AnalysisType::BRIEFING_SUBJECT_TYPE;
    }

    #[Override]
    protected function resolveSubject(int $id): User
    {
        $user = User::query()->find($id);
        if ($user === null) {
            throw new UnavailableException("User {$id} not found");
        }

        return $user;
    }

    #[Override]
    protected function generateAll(mixed $subject): array
    {
        /** @var User $subject */
        $asOf = $this->discriminator !== null ? Carbon::parse($this->discriminator) : Carbon::today();
        $payload = app(BriefingNarrator::class)->generate($subject, $asOf);

        return [
            AnalysisType::BriefingHeadline->value => $payload['headline'],
            AnalysisType::BriefingSuggestion->value => $payload['suggestion'],
        ];
    }
}
