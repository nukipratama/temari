<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\PaceCalculator;
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
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('analyzed_at'))
            ->with(['activity.runCard:id,activity_id,rarity,special_move,badges'])
            ->orderByDesc('start_date_local')
            ->limit(8)
            ->get();

        $lastRunNote = $this->lastRunNote($recentRuns->first()?->activity_id);

        return Inertia::render('HariIni', [
            'briefing' => $briefing,
            'load' => $load,
            'snapshot' => $weeks->last(),
            'recentRuns' => $recentRuns,
            'lastRunNote' => $lastRunNote,
            'chartData' => $this->fitnessChartData($weeks),
            'trendAnalysis' => $this->resolveTrendCaption($user, $today),
            'hasNewPr' => $this->detectNewPr($user),
            'pendingMilestone' => $this->resolvePendingMilestone($user),
            'weekVsLastWeek' => $this->resolveWeekVsLastWeek($weeks),
        ]);
    }

    /**
     * Post-run note (mood + oneline) for the user's most-recent activity, if
     * the StoryLine + PostRunSpeech analysis are both ready.
     *
     * @return array{oneline: string, mood: string}|null
     */
    private function lastRunNote(?int $activityId): ?array
    {
        if ($activityId === null) {
            return null;
        }

        $mood = StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->where('activity_id', $activityId)
            ->value('mood');

        $speech = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->where('subject_id', $activityId)
            ->value('content');

        if ($mood === null || ! is_string($speech) || $speech === '') {
            return null;
        }

        return ['oneline' => $speech, 'mood' => $mood];
    }

    /**
     * @param  Collection<int, WeeklySnapshot>  $weeks  Chronological (oldest → newest).
     * @return array{distance_delta_km: float, runs_delta: int, pace_delta_sec: float|null, this_week_km: float, this_week_runs: int}|null
     */
    private function resolveWeekVsLastWeek(Collection $weeks): ?array
    {
        if ($weeks->count() < 2) {
            return null;
        }

        // Both are non-null because count >= 2 (guarded above).
        /** @var WeeklySnapshot $thisWeek */
        $thisWeek = $weeks->last();
        /** @var WeeklySnapshot $lastWeek */
        $lastWeek = $weeks->slice(-2, 1)->first();

        $paceDelta = null;
        $thisPace = self::weekPaceSecPerKm($thisWeek);
        $lastPace = self::weekPaceSecPerKm($lastWeek);
        if ($thisPace !== null && $lastPace !== null) {
            $paceDelta = $thisPace - $lastPace;
        }

        return [
            'distance_delta_km' => (float) (($thisWeek->distance_km ?? 0) - ($lastWeek->distance_km ?? 0)),
            'runs_delta' => (int) (($thisWeek->runs ?? 0) - ($lastWeek->runs ?? 0)),
            'pace_delta_sec' => $paceDelta,
            'this_week_km' => (float) ($thisWeek->distance_km ?? 0),
            'this_week_runs' => (int) ($thisWeek->runs ?? 0),
        ];
    }

    private static function weekPaceSecPerKm(WeeklySnapshot $snapshot): ?float
    {
        // Real pace: total moving seconds over total distance for the week.
        // moving_time_sec is null for snapshots written before it was tracked;
        // those return null until the next aggregation backfills the column.
        $km = $snapshot->distance_km;

        return PaceCalculator::secPerKm(
            $km === null ? null : $km * 1000,
            $snapshot->moving_time_sec,
        );
    }

    /**
     * Most-recent activity with un-dismissed milestone payload. Returns the
     * activity id so the frontend can POST a dismiss back; the payload
     * itself is the cached MilestoneDetector output.
     *
     * @return array{activity_id: int, milestones: list<array<string, mixed>>}|null
     */
    private function resolvePendingMilestone(User $user): ?array
    {
        $activity = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('milestone_payload')
            ->orderByDesc('id')
            ->first();

        if ($activity === null) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $payload */
        $payload = $activity->milestone_payload;

        return [
            'activity_id' => $activity->id,
            'milestones' => array_values($payload),
        ];
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
