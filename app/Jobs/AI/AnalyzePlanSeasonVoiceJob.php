<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
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
     * Stamped so a later regeneration re-narrates exactly once when the
     * sustained-ahead signal or current-week adaptation changes; everything
     * else about a season's material is fixed at creation.
     */
    protected function fingerprintFor(Analysis $row): ?string
    {
        $season = Season::query()->find($row->subject_id);
        if ($season === null) {
            return null;
        }

        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $adaptation = PlanAdaptation::query()
            ->where('user_id', $season->user_id)
            ->where('week_start', $weekStart->toDateString())
            ->first();

        return MaterialFingerprint::forSeason(
            app(SustainedAheadOfRacePace::class)->forUser($season->user_id, $weekStart),
            $adaptation?->reason,
            $adaptation === null ? false : $adaptation->deload,
        );
    }
}
