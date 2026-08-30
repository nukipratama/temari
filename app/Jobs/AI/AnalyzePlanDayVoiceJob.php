<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\Narrators\PlanDayVoiceNarrator;

class AnalyzePlanDayVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->findOrFail($row->subject_id);
        $date = $this->discriminatorDate($row);

        $session = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $date->toDateString())
            ->first();

        if ($session === null) {
            throw new UnavailableException("No PlannedSession for user {$user->id} on {$date->toDateString()}");
        }

        return app(PlanDayVoiceNarrator::class)->generate($session);
    }
}
