<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\Season;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\Narrators\PlanSeasonVoiceNarrator;
use App\Services\Run\Plan\SustainedAheadOfRacePace;
use Illuminate\Support\Carbon;

class AnalyzePlanSeasonVoiceJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $season = Season::query()->find($row->subject_id);
        if ($season === null) {
            throw new UnavailableException("Season {$row->subject_id} not found");
        }

        return app(PlanSeasonVoiceNarrator::class)->generate($season);
    }

    /**
     * Stamped so a later regeneration re-narrates exactly once when
     * {@see SustainedAheadOfRacePace} flips — everything else about a season's
     * material is fixed at creation, see {@see MaterialFingerprint::forSeason()}.
     */
    protected function fingerprintFor(Analysis $row): ?string
    {
        $season = Season::query()->find($row->subject_id);
        if ($season === null) {
            return null;
        }

        return MaterialFingerprint::forSeason(
            app(SustainedAheadOfRacePace::class)->forUser($season->user_id, Carbon::today()->startOfWeek(Carbon::MONDAY)),
        );
    }
}
