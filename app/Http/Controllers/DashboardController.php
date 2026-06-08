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
use App\Services\Gamification\WeeklyRecapBuilder;
use App\Services\Run\Metrics\TrainingLoad;
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
            'trendAnalysis' => $this->resolveTrendCaption($user, $today),
            'hasNewPr' => $this->detectNewPr($user),
            'pendingMilestone' => $this->resolvePendingMilestone($user),
            'weeklyRecap' => $weeklyRecapBuilder->forUser($user, $today),
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

    /**
     * Read-only detection of a fresh, unseen PR. The dashboard GET must not
     * mutate the user: advancing the "seen" marker happens on an explicit
     * POST (PrLedgerController::seen) when the celebration UI is dismissed.
     */
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
}
