<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    private const array RUN_INSIGHT_TYPES = [
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones,
    ];

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $runs = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->with(['detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards'])])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $activityIds = collect($runs->items())->pluck('id')->all();

        return Inertia::render('Runs/Index', [
            'runs' => $runs,
            'notes' => $this->notesForActivities($activityIds),
        ]);
    }

    /**
     * @param  array<int, int>  $activityIds
     * @return array<int, array{oneline: string, mood: string}>
     */
    private function notesForActivities(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        $moodByActivity = StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereIn('activity_id', $activityIds)
            ->pluck('mood', 'activity_id');

        $speechByActivity = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->whereIn('subject_id', $activityIds)
            ->pluck('content', 'subject_id');

        $notes = [];
        foreach ($activityIds as $id) {
            $speech = $speechByActivity->get($id);
            $mood = $moodByActivity->get($id);
            if ($speech === null || $speech === '' || $mood === null) {
                continue;
            }
            $notes[$id] = ['oneline' => $speech, 'mood' => $mood];
        }

        return $notes;
    }

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($activity->user_id === $user->id, 404);

        $activity->loadMissing(['detail', 'runCard']);
        $detail = $activity->detail;
        abort_if($detail === null, 404, 'Activity not yet analyzed.');

        $storyLine = StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();

        $analyses = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('subject_id', $activity->id)
            ->whereIn('analysis_type', self::RUN_INSIGHT_TYPES)
            ->get()
            ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);

        if ($detail->start_lat !== null && $detail->location_resolved_at === null) {
            ResolveActivityLocationJob::dispatch($detail->id);
        }

        $payloadFor = fn (AnalysisType $type): array => Analysis::toPayload(
            $analyses->get($type->value),
            $type,
            Activity::class,
            $activity->id,
        );

        return Inertia::render('Runs/Show', [
            'activity' => $activity,
            'detail' => $detail,
            'card' => $activity->runCard,
            'storyLine' => $storyLine,
            'speechAnalysis' => $payloadFor(AnalysisType::PostRunSpeech),
            'insightTechnical' => $payloadFor(AnalysisType::RunInsightTechnical),
            'insightSplits' => $payloadFor(AnalysisType::RunInsightSplits),
            'insightZones' => $payloadFor(AnalysisType::RunInsightZones),
            'pastYou' => $matcher->findMatch($activity, $detail),
        ]);
    }
}
