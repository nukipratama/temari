<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /trends — "am I getting fitter, and at what cost?" Temari's 7-day verdict
 * up top, then three stacked comparisons: this week against last (the gain
 * and the cost), fitness now against a month ago (the CTL chart lives here),
 * and now against race day — or, with no race set, against the athlete's own
 * year.
 */
class TrendsController extends Controller
{
    public function __invoke(
        Request $request,
        TrainingLoad $trainingLoad,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        return Inertia::render('Trends', [
            'weekComparison' => Inertia::defer(fn (): array => $this->weekComparison($user, $today)),
            'load' => Inertia::defer(fn (): ?array => $trainingLoad->summary($user, $today, 7)),
            'ctlTrend' => Inertia::defer(fn (): array => $trainingLoad->ctlTrend($user, 365)),
            'chartAnnotations' => Inertia::defer(fn (): array => $this->chartAnnotations($user, $today)),
            'narration' => Inertia::defer(fn (): array => $this->narration($user)),
        ]);
    }

    /**
     * The gain half of "vs last week" — km and runs this week against last
     * week through the same weekday. Reuses BriefingContext rather than a new
     * query: it already computes this exact comparison for the daily
     * briefing's tool context.
     *
     * @return array{this_week_km: float|null, last_week_km: float|null, this_week_runs: int|null, last_week_runs: int|null}
     */
    private function weekComparison(User $user, Carbon $today): array
    {
        $context = BriefingContext::forUser($user, $today);

        return [
            'this_week_km' => $context->thisWeekKm,
            'last_week_km' => $context->lastWeekKm,
            'this_week_runs' => $context->thisWeekRuns,
            'last_week_runs' => $context->lastWeekRuns,
        ];
    }

    /**
     * Deload weeks and race day, read off the athlete's plan history rather
     * than guessed from the fitness curve — the fitness chart's markers.
     *
     * @return array{deload: array<int, string>, race: array<int, string>}
     */
    private function chartAnnotations(User $user, Carbon $today): array
    {
        $start = $today->copy()->subDays(TrainingLoad::CONVERGED_LOOKBACK_DAYS - 1);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $today->toDateString()])
            ->where(fn ($query) => $query
                ->where('phase', PlanPhase::Deload)
                ->orWhere('session_type', SessionType::Race))
            ->get(['date', 'phase', 'session_type']);

        return [
            'deload' => $sessions->where('phase', PlanPhase::Deload)
                ->map(fn (PlannedSession $session): string => $session->date->toDateString())
                ->values()
                ->all(),
            'race' => $sessions->where('session_type', SessionType::Race)
                ->map(fn (PlannedSession $session): string => $session->date->toDateString())
                ->values()
                ->all(),
        ];
    }

    /**
     * The verdict card's own Analysis payload — the single 7d row. Trends
     * used to narrate 30d/90d/12mo alongside it; those retired (#967).
     *
     * @return array<string, mixed>
     */
    private function narration(User $user): array
    {
        $range = AnalysisType::TREND_READ_RANGES[0];

        $row = Analysis::query()
            ->forSubject(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, $range)
            ->first();

        return Analysis::toPayload(
            $row,
            AnalysisType::TrendRead,
            AnalysisType::TREND_READ_SUBJECT_TYPE,
            $user->id,
            $range,
        );
    }
}
