<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AI\Analysis;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Models\User;

/**
 * Row job for the dashboard's daily Temari voice ("Kata Temari hari ini"): the
 * single billed call that carries both the day's reading and the session it
 * implies.
 */
class AnalyzeBriefingMascotVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->findOrFail($row->subject_id);
        $asOf = $this->discriminatorDate($row);

        return app(BriefingMascotVoiceNarrator::class)->generate($user, $asOf);
    }
}
