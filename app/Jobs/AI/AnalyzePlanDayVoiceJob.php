<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\Narrators\PlanDayVoiceNarrator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;

class AnalyzePlanDayVoiceJob extends AnalyzeRowJob
{
    private ?PlannedSession $session = null;

    protected function generateContent(Analysis $row): string
    {
        return app(PlanDayVoiceNarrator::class)->generate($this->sessionFor($row));
    }

    protected function fingerprintFor(Analysis $row): ?string
    {
        $session = $this->sessionFor($row);

        return MaterialFingerprint::forPlannedSession(
            $session,
            app(TrainingBaseline::class)->forUser($session->user, Carbon::today())['long_run_km'],
        );
    }

    /** Memoized: generateContent() resolves it, fingerprintFor() reads it back. */
    private function sessionFor(Analysis $row): PlannedSession
    {
        if ($this->session !== null) {
            return $this->session;
        }

        $date = $this->discriminatorDate($row)->toDateString();
        $session = PlannedSession::query()
            ->where('user_id', $row->subject_id)
            ->where('date', $date)
            ->first();

        if ($session === null) {
            throw new UnavailableException("No PlannedSession for user {$row->subject_id} on {$date}");
        }

        return $this->session = $session;
    }
}
