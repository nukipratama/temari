<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingComposer;
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
        AnalysisService $analysisService,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        $this->resolveGreeting($user, $temari, $vibe->current($user, $today), $today);

        $load = $trainingLoad->summary($user, $today);
        $briefing = $briefingComposer->compose($user, $today);

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
            'load' => $load,
            'snapshot' => $weeks->last(),
            'recentRuns' => $recentRuns,
            'chartData' => $this->fitnessChartData($weeks),
            'trendAnalysis' => $this->resolveTrendCaption($user, $today, $analysisService),
            'hasNewPr' => $this->detectNewPr($user),
        ]);
    }

    private function detectNewPr(User $user): bool
    {
        $latest = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderByDesc('set_at')
            ->value('set_at');

        if ($latest === null) {
            return false;
        }

        $latestAt = Carbon::parse($latest);
        $seenAt = $user->last_seen_pr_ledger_at;

        if ($seenAt !== null && $seenAt->gte($latestAt)) {
            return false;
        }

        $user->forceFill(['last_seen_pr_ledger_at' => $latestAt])->save();

        return true;
    }

    /**
     * @return array{
     *     id: int|null,
     *     status: string,
     *     content: string|null,
     *     type: string,
     *     subject_type: string,
     *     subject_id: int,
     *     discriminator: string|null,
     * }
     */
    private function resolveTrendCaption(User $user, Carbon $today, AnalysisService $analysisService): array
    {
        $discriminator = $today->toDateString();
        $subjectType = AnalysisType::TREND_CAPTION_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::TrendCaption, $discriminator)
            ->first();

        if ($row === null) {
            $row = $analysisService->request(
                subjectOrType: $subjectType,
                subjectId: $user->id,
                type: AnalysisType::TrendCaption,
                discriminator: $discriminator,
            );
        }

        return Analysis::toPayload($row, AnalysisType::TrendCaption, $subjectType, $user->id, $discriminator);
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
