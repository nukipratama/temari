<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use App\Services\Run\Story\Contracts\VerdictNarrator;
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
        BriefingNarrator $briefingService,
        VerdictNarrator $verdictTimeline,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        $this->resolveGreeting($user, $temari, $vibe->current($user, $today), $today);

        $load = $trainingLoad->summary($user, $today);
        $briefing = $briefingService->generate($user, $today);
        $verdicts = $verdictTimeline->recent($user);

        $weeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        $recentRuns = ActivityDetail::query()
            ->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards'])
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('analyzed_at'))
            ->orderByDesc('start_date_local')
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard', [
            'briefing' => $briefing,
            'verdicts' => $verdicts,
            'load' => $load,
            'snapshot' => $weeks->last(),
            'recentRuns' => $recentRuns,
            'chartData' => $this->fitnessChartData($weeks),
        ]);
    }

    /**
     * One greeting per (user, day) — generate on first dashboard open, reuse
     * for the rest of the day.
     */
    private function resolveGreeting(User $user, Temari $temari, string $vibeState, Carbon $today): StoryLine
    {
        $existing = StoryLine::query()
            ->where('user_id', $user->id)
            ->where('kind', StoryLine::KIND_DAILY_GREETING)
            ->where('for_date', $today->toDateString())
            ->first();

        return $existing ?? $temari->dailyGreeting($user, $vibeState, $today);
    }

    /**
     * @param  Collection<int, WeeklySnapshot>  $rows
     * @return array{labels: array<int, string>, ctl: array<int, ?float>, atl: array<int, ?float>, form: array<int, ?float>, volume: array<int, ?float>}
     */
    private function fitnessChartData(Collection $rows): array
    {
        return [
            'labels' => $rows->map(fn ($r): string => $r->week_ending->toDateString())->all(),
            'ctl' => $rows->pluck('ctl_42d')->all(),
            'atl' => $rows->pluck('atl_7d')->all(),
            'form' => $rows->pluck('form')->all(),
            'volume' => $rows->pluck('distance_km')->all(),
        ];
    }
}
