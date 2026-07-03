<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Gamification\WeeklyRecapBuilder;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\PostRunNoteReader;
use App\Services\Run\Story\BriefingComposer;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
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
        WeeklyRecapBuilder $weeklyRecapBuilder,
        PostRunNoteReader $noteReader,
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
            ->select([
                'id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time',
                'average_heartrate', 'trimp_edwards',
                'location_name', 'weather_temp_c', 'weather_humidity_pct', 'weather_rain_detected',
                // Needed so the featured + strip cards draw the route hero and the
                // featured card's zone bar / pace-shape / cadence / best-km.
                'summary_polyline', 'stream_summary',
            ])
            ->forUser($user->id)
            ->with(['activity.runCard:id,activity_id,rarity,special_move,badges'])
            ->orderByDesc('start_date_local')
            ->limit(8)
            ->get();

        $lastRunActivityId = $recentRuns->first()?->activity_id;
        $lastRunNote = $lastRunActivityId === null ? null : $noteReader->forActivity($lastRunActivityId);

        return Inertia::render('HariIni', [
            'briefing' => $briefing,
            'load' => $load,
            'snapshot' => $weeks->last(),
            'recentRuns' => $recentRuns,
            'lastRunNote' => $lastRunNote,
            // Persisted post-run mood per recent run, so the featured card and
            // last-run mascot match the backend mood without a frontend heuristic.
            'recentMoods' => $noteReader->moodsFor($recentRuns->pluck('activity_id')->all()),
            'trendAnalysis' => $this->resolveTrendCaption($user, $today),
            'weeklyRecap' => $weeklyRecapBuilder->forUser($user, $today),
        ]);
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
    private function resolveTrendCaption(User $user, Carbon $today): array
    {
        $discriminator = $today->toDateString();
        $subjectType = AnalysisType::TREND_CAPTION_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::TrendCaption, $discriminator)
            ->first();

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
}
