<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PrCategory;
use App\Models\AI\Analysis;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /trends — a year of running read as lines rather than a list. This slice
 * adds the Load, VDOT and Pace Consistency panels; Personal Bests and badge
 * milestones land in the final slice per the approved build plan.
 */
class TrendsController extends Controller
{
    public function __invoke(Request $request, TrainingLoad $trainingLoad, VdotEstimator $vdotEstimator): Response
    {
        /** @var User $user */
        $user = $request->user();
        $snapshots = $this->snapshotHistory($user);
        $vdot = $vdotEstimator->estimate($user);

        return Inertia::render('Trends', [
            'ctlTrend' => $trainingLoad->ctlTrend($user, 365),
            'loadTrend' => $trainingLoad->strainMonotonyTrend($user, 365),
            'vdotHistory' => array_map(static fn (TrendDailySnapshot $s): array => [
                'date' => $s->snapshot_date->toDateString(),
                'vdot' => $s->vdot,
            ], $snapshots),
            'vdotSourceCategory' => $vdot !== null ? PrCategory::from($vdot['source_category'])->label() : null,
            'paceConsistencyHistory' => array_map(static fn (TrendDailySnapshot $s): array => [
                'date' => $s->snapshot_date->toDateString(),
                'variabilitySec' => $s->pace_variability_sec,
            ], $snapshots),
            'narration' => $this->narrationByRange($user),
        ]);
    }

    /**
     * @return array<int, TrendDailySnapshot>
     */
    private function snapshotHistory(User $user, int $days = 365): array
    {
        return TrendDailySnapshot::query()
            ->where('user_id', $user->id)
            ->where('snapshot_date', '>=', now()->subDays($days - 1)->toDateString())
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'vdot', 'pace_variability_sec'])
            ->all();
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
