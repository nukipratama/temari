<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Story\BriefingComposer;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\PastYouTrendBuilder;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        Vibe $vibe,
        Temari $temari,
        TrainingLoad $trainingLoad,
        BriefingComposer $briefingComposer,
        PastYouTrendBuilder $pastYouTrend,
        CurrentWeekPlanBuilder $weekPlanBuilder,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        // Writes today's greeting row on first visit of the day. Kept in the
        // method body, not behind a prop closure: nothing on this page renders
        // it, so a closure would silently stop persisting it.
        $this->resolveGreeting($user, $temari, $vibe->current($user, $today), $today);

        // Deferred behind a closure (Inertia's `useAnalysisTrigger` poll skips
        // any prop the partial reload does not name) and memoized.
        /** @var Collection<int, ActivityDetail>|null $loadedRecentRuns */
        $loadedRecentRuns = null;
        $loadRecentRuns = function () use ($user, &$loadedRecentRuns): Collection {
            /** @var Collection<int, ActivityDetail> */
            return $loadedRecentRuns ??= ActivityDetail::query()
                ->select([
                    'id', 'activity_id', 'name', 'start_date_local',
                    'distance', 'elapsed_time', 'average_heartrate', 'trimp_edwards',
                ])
                ->forUser($user->id)
                ->orderByDesc('start_date_local')
                ->limit(8)
                ->get();
        };

        return Inertia::render('Home', [
            'briefing' => fn (): BriefingResult => $briefingComposer->compose($user, $today),
            'load' => fn (): ?array => $trainingLoad->summary($user, $today),
            'snapshot' => fn (): ?WeeklySnapshot => WeeklySnapshot::query()
                ->where('user_id', $user->id)
                ->orderByDesc('week_ending')
                ->first(),
            'recentRuns' => fn (): Collection => $loadRecentRuns(),
            'pastYouTrend' => fn (): array => $pastYouTrend->build($user, $today)->toArray(),
            'weekPlan' => fn (): ?array => $weekPlanBuilder->forUser($user, $today),
        ]);
    }

    private function resolveGreeting(User $user, Temari $temari, string $vibeState, Carbon $today): StoryLine
    {
        $existing = StoryLine::query()
            ->where('user_id', $user->id)
            ->where('kind', StoryLine::KIND_DAILY_GREETING)
            ->where('for_date', $today->toDateString())
            ->first();

        return $existing ?? $temari->dailyGreeting($user, $vibeState, $today);
    }
}
