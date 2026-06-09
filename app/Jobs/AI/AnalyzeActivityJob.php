<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\StoryLine;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\RuleBased\RuleBasedInsightBuilder;
use Override;

class AnalyzeActivityJob extends AnalyzeGroupJob
{
    #[Override]
    public static function groupedTypes(): array
    {
        return AnalysisType::groupedBy(self::class);
    }

    #[Override]
    public static function subjectType(): string
    {
        return Activity::class;
    }

    #[Override]
    protected function resolveSubject(int $id): Activity
    {
        $activity = Activity::query()->with('detail')->find($id);
        if ($activity === null || $activity->detail === null) {
            throw new UnavailableException("Activity {$id} not analyzed yet");
        }

        return $activity;
    }

    #[Override]
    protected function generateAll(mixed $subject): array
    {
        /** @var Activity $subject */
        $detail = $subject->detail;
        assert($detail !== null); // resolveSubject guarantees non-null

        $storyLine = StoryLine::query()
            ->where('activity_id', $subject->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();
        if ($storyLine === null) {
            throw new UnavailableException("StoryLine for activity {$subject->id} missing");
        }

        $speech = app(PostRunSpeechNarrator::class)
            ->generate($subject, $detail, $storyLine->mood);

        $insights = app(RuleBasedInsightBuilder::class)->runInsights($subject, $detail);

        return [
            AnalysisType::PostRunSpeech->value => $speech,
            AnalysisType::RunInsightTechnical->value => $insights['technical'],
            AnalysisType::RunInsightSplits->value => $insights['splits'],
            AnalysisType::RunInsightZones->value => $insights['zones'],
        ];
    }
}
