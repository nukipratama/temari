<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingComposer;
use App\Services\Run\Story\BriefingResult;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /trends — a year of running read as lines rather than a list: the headline,
 * the load section Home used to hold behind a disclosure, the range tabs,
 * Temari's read, and one fitness panel carrying the CTL/ATL chart, its stat
 * tiles and the badges earned in the window.
 */
class TrendsController extends Controller
{
    public function __invoke(
        Request $request,
        TrainingLoad $trainingLoad,
        SeasonStreakSummaryBuilder $seasonStreakBuilder,
        BriefingComposer $briefingComposer,
        ResolveTrailingWeeksAction $trailingWeeks,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        return Inertia::render('Trends', [
            'briefing' => Inertia::defer(fn (): BriefingResult => $briefingComposer->compose($user, $today)),
            'load' => Inertia::defer(fn (): ?array => $trainingLoad->summary($user, $today)),
            'snapshot' => Inertia::defer(fn (): ?WeeklySnapshot => $trailingWeeks(
                $user->id,
                $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                1,
            )->first()),
            'ctlTrend' => Inertia::defer(fn (): array => $trainingLoad->ctlTrend($user, 365)),
            'badgeMilestones' => Inertia::defer(fn (): array => collect(RunCard::firstEarnedBadgesForUser($user->id))
                ->map(static fn (array $earned, string $slug): array => [
                    'key' => $slug,
                    'date' => $earned['date'],
                    'rarity' => $earned['rarity'],
                ])
                ->values()
                ->all()),
            'streak' => Inertia::defer(fn (): array => $seasonStreakBuilder->streakPayload($user, Carbon::today())),
            'narration' => Inertia::defer(fn (): array => $this->narrationByRange($user)),
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
