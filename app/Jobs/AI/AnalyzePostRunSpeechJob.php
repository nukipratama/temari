<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use Override;

class AnalyzePostRunSpeechJob extends AnalyzeAbstractJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $activity = Activity::query()->with('detail')->find($row->subject_id);
        if ($activity === null || $activity->detail === null) {
            throw new UnavailableException("Activity {$row->subject_id} not analyzed yet");
        }

        $storyLine = StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();

        if ($storyLine === null) {
            throw new UnavailableException("StoryLine for activity {$activity->id} missing");
        }

        return app(PostRunSpeechNarrator::class)
            ->generate($activity, $activity->detail, $storyLine->mood);
    }
}
