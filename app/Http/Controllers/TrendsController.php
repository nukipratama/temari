<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /trends — a year of running read as lines rather than a list. This first
 * slice renders the Fitness/Fatigue panel + its narration only; the
 * remaining panels (Load, VDOT, Consistency, Personal Bests, badge
 * milestones) land in later slices per the approved build plan.
 */
class TrendsController extends Controller
{
    public function __invoke(Request $request, TrainingLoad $trainingLoad): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Trends', [
            'ctlTrend' => $trainingLoad->ctlTrend($user, 365),
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
