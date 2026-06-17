<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\StoryLine;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
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

        $insights = $this->runInsights($subject);

        return [
            AnalysisType::PostRunSpeech->value => $speech,
            AnalysisType::RunInsightTechnical->value => $insights['technical'],
            AnalysisType::RunInsightSplits->value => $insights['splits'],
            AnalysisType::RunInsightZones->value => $insights['zones'],
        ];
    }

    /**
     * LLM run-insight (gpt-5.2 with historical context), degrading to the
     * deterministic rule-based builder if the model is unavailable rather than
     * failing the whole activity group (which also holds the post-run speech).
     *
     * @return array{technical: string, splits: string, zones: string}
     */
    private function runInsights(Activity $activity): array
    {
        $detail = $activity->detail;
        assert($detail !== null);

        try {
            return app(RunInsightNarrator::class)->generate($activity, $detail);
        } catch (UnavailableException|TransientUpstreamException) {
            return app(RuleBasedInsightBuilder::class)->runInsights($activity, $detail);
        }
    }
}
