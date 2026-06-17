<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\Run\Story\Vibe;
use Override;

class AnalyzeDailyGreetingJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            throw new UnavailableException("User {$row->subject_id} not found");
        }

        $asOf = $this->discriminatorDate($row);
        $vibeState = app(Vibe::class)->current($user, $asOf);

        return app(DailyGreetingNarrator::class)->generate($user, $vibeState, $asOf);
    }
}
