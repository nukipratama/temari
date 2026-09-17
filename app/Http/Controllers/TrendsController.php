<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
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
    /**
     * The range toggle's own four windows, days ending on today. Wider than
     * {@see AnalysisType::TREND_READ_RANGES}: the load section follows every
     * toggle position, narration only the three that are actually generated.
     *
     * @var array<string, int>
     */
    private const array RANGE_WINDOW_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90, '12mo' => 365];

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
            'load' => Inertia::defer(fn (): array => $this->loadByRange($trainingLoad, $user, $today)),
            'snapshot' => Inertia::defer(fn (): ?WeeklySnapshot => $trailingWeeks(
                $user->id,
                $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                1,
            )->first()),
            'ctlTrend' => Inertia::defer(fn (): array => $trainingLoad->ctlTrend($user, 365)),
            'chartAnnotations' => Inertia::defer(fn (): array => $this->chartAnnotations($user, $today)),
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
     * The load section's condition read, once per toggle position — mirrors
     * {@see self::narrationByRange()}'s "compute every range up front" shape
     * so switching the toggle never round-trips to the server. ATL/CTL/form
     * are the same across every entry (EWMA time constants, not a window);
     * only weekly_trimp/monotony/strain move.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function loadByRange(TrainingLoad $trainingLoad, User $user, Carbon $today): array
    {
        return collect(self::RANGE_WINDOW_DAYS)
            ->map(fn (int $days): ?array => $trainingLoad->summary($user, $today, $days))
            ->all();
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
