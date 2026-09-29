<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\AI\RunQuestion;
use App\Services\AI\AnalysisStatus;

final class GetThreadTool extends NoArgumentTool
{
    public const int MAX_EXCHANGES = 6;

    public function __construct(
        private readonly Activity $activity,
        private readonly int $answeringQuestionId,
    ) {
    }

    public function name(): string
    {
        return 'get_thread';
    }

    public function description(): string
    {
        return 'The earlier questions the athlete asked about this same run, and what you answered, '
            .'oldest first. Call this when the question refers back to something already said '
            .'("why", "that", "it", "what about..."). An empty list means this is the first question.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $exchanges = RunQuestion::query()
            ->where('activity_id', $this->activity->id)
            ->where('user_id', $this->activity->user_id)
            ->where('status', AnalysisStatus::Done)
            ->where('id', '<', $this->answeringQuestionId)
            ->orderByDesc('id')
            ->limit(self::MAX_EXCHANGES)
            ->get(['question', 'answer'])
            ->reverse()
            ->map(fn (RunQuestion $row): array => ['question' => $row->question, 'answer' => $row->answer])
            ->values()
            ->all();

        return ['thread' => $exchanges];
    }
}
