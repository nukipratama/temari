<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /trends — a year of running read as lines rather than a list. Four blocks
 * only (P25): the headline, the range tabs, Temari's read, and one fitness
 * panel carrying the CTL/ATL chart, its stat tiles and the badges earned in
 * the window.
 */
class TrendsController extends Controller
{
    public function __invoke(Request $request, TrainingLoad $trainingLoad, SeasonStreakSummaryBuilder $seasonStreakBuilder): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Trends', [
            'ctlTrend' => $trainingLoad->ctlTrend($user, 365),
            'badgeMilestones' => collect(RunCard::firstEarnedDatesForUser($user->id))
                ->map(static fn (string $date, string $slug): array => ['key' => $slug, 'date' => $date])
                ->values()
                ->all(),
            'streak' => $seasonStreakBuilder->streakPayload($user, Carbon::today()),
            'narration' => $this->narrationByRange($user),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function narrationByRange(User $user): array
    {
        $narration = [];
        foreach (AnalysisType::TREND_READ_RANGES as $range) {
            $row = Analysis::query()
                ->forSubject(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, $range)
                ->first();

            $narration[$range] = Analysis::toPayload(
                $row,
                AnalysisType::TrendRead,
                AnalysisType::TREND_READ_SUBJECT_TYPE,
                $user->id,
                $range,
            );
        }

        return $narration;
    }
}
