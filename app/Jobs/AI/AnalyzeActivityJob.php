<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use Illuminate\Database\Eloquent\Builder;
use App\Models\StoryLine;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\AI\RuleBased\RuleBasedInsightBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class AnalyzeActivityJob extends AnalyzeGroupJob
{
    #[Override]
    public static function groupedTypes(): array
    {
        return AnalysisType::groupedBy(self::class);
    }

    #[Override]
    public static function subjectType(): string
    {
        return Activity::class;
    }

    #[Override]
    protected function resolveSubject(int $id): Activity
    {
        $activity = Activity::query()->with('detail')->find($id);
        if ($activity === null || $activity->detail === null) {
            throw new UnavailableException("Activity {$id} not analyzed yet");
        }

        return $activity;
    }

    /**
     * Chain propagation (group-level): once this activity's whole narration
     * group is Done, dispatch the next chronological activity's group (by
     * start_date_local) for the same user whose group is still Pending. The
     * per-activity chain advances per activity (the whole group), not per row,
     * so this is overridden at the group level rather than per AnalyzeRowJob.
     * The next group is dispatched via requestActivityGroup(invalidate:false),
     * which is a clean no-op under a tripped cost ceiling or AI-disabled env,
     * so the chain pauses (rows stay Pending, no filler) rather than breaking
     * the connected story. Each successor reads its predecessor's already-Done
     * post_run / run_insight narrative.
     *
     * Best-effort: any failure here (DB blip, dispatch error) is logged and
     * swallowed so it never flips this already-Done, already-billed group back
     * to Failed. The daily resume sweep re-kicks any link this misses.
     */
    #[Override]
    protected function afterGroupDone(AnalysisService $service): void
    {
        try {
            $next = self::earliestPendingActivity($this->subjectId);
            if ($next === null) {
                return;
            }

            $service->requestActivityGroup(
                $next,
                invalidate: false,
                delaySeconds: (int) config('ai.backfill_stagger_seconds', 360),
            );
        } catch (Throwable $e) {
            Log::warning('ai.activity_chain_advance_failed', [
                'activity_id' => $this->subjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The earliest activity (by start_date_local) for the user owning
     * $afterActivityId whose narration group is still Pending and that is
     * strictly later than $afterActivityId. Drives both the chain advance (the
     * "next" link) and, when $afterActivityId is the chain head, lets the
     * listener kickoff and the daily resume sweep find the same earliest link.
     * "Pending group" is matched on the representative PostRunSpeech row, since
     * the whole group is staged and narrated together.
     */
    public static function earliestPendingActivity(int $afterActivityId): ?Activity
    {
        $after = Activity::query()
            ->with('detail:id,activity_id,start_date_local')
            ->find($afterActivityId);
        $afterDate = $after?->detail?->start_date_local;
        if ($after === null || $afterDate === null) {
            return null;
        }

        return self::earliestPendingActivityForUser($after->user_id, $afterDate);
    }

    /**
     * The user's earliest activity (by start_date_local) with a Pending
     * narration group, optionally only those started strictly after $after.
     * Returns null when the chain has no remaining Pending link.
     */
    public static function earliestPendingActivityForUser(int $userId, ?Carbon $after = null): ?Activity
    {
        return Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->when($after !== null, fn ($query) => $query->where('activity_details.start_date_local', '>', $after))
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::PostRunSpeech)
                ->where('status', AnalysisStatus::Pending))
            ->orderBy('activity_details.start_date_local')
            ->select('activities.*')
            ->first();
    }

    /**
     * The user's earliest activity (by start_date_local) whose narration group is
     * *stalled* on its representative PostRunSpeech row: Pending, or Failed still
     * under the self-heal retry budget ({@see Analysis::scopeStalled}). Unlike
     * {@see self::earliestPendingActivityForUser()} (Pending-only, which drives
     * the chain advance), this also recovers a Failed-under-budget group so
     * ai:self-heal can retry it, bounded to dead-letter. Returns null when the
     * user has no stalled per-activity group left.
     */
    public static function earliestStalledActivityForUser(int $userId): ?Activity
    {
        return Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->whereHas('analyses', function ($query): void {
                /** @var Builder<Analysis> $query */
                $query
                    ->where('analysis_type', AnalysisType::PostRunSpeech)
                    ->stalled();
            })
            ->orderBy('activity_details.start_date_local')
            ->select('activities.*')
            ->first();
    }

    #[Override]
    protected function generateAll(mixed $subject): array
    {
        /** @var Activity $subject */
        $detail = $subject->detail;
        assert($detail !== null); // resolveSubject guarantees non-null

        $storyLine = StoryLine::query()
            ->where('activity_id', $subject->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();
        if ($storyLine === null) {
            throw new UnavailableException("StoryLine for activity {$subject->id} missing");
        }

        $insights = $this->resolveInsights($subject, $detail);

        $speech = app(PostRunSpeechNarrator::class)
            ->generate($subject, $detail, $storyLine->mood, $insights);

        return [
            AnalysisType::PostRunSpeech->value => $speech,
            AnalysisType::RunInsightTechnical->value => $insights['technical'],
            AnalysisType::RunInsightSplits->value => $insights['splits'],
            AnalysisType::RunInsightZones->value => $insights['zones'],
        ];
    }

    /**
     * The three run-insight analyses the post-run speech synthesizes. Reused
     * verbatim from already-Done insight rows when present (so a cerita-only
     * re-dispatch does not re-bill the insight LLM); otherwise generated fresh
     * via {@see self::runInsights()}.
     *
     * @return array{technical: string, splits: string, zones: string}
     */
    private function resolveInsights(Activity $activity, ActivityDetail $detail): array
    {
        $reused = $this->doneInsights($activity);
        if ($reused !== null) {
            return $reused;
        }

        return $this->runInsights($activity, $detail);
    }

    /**
     * The activity's three insight analyses if all of them are already Done with
     * non-null content, else null (signalling a fresh generation is needed).
     *
     * @return array{technical: string, splits: string, zones: string}|null
     */
    private function doneInsights(Activity $activity): ?array
    {
        $types = [
            'technical' => AnalysisType::RunInsightTechnical,
            'splits' => AnalysisType::RunInsightSplits,
            'zones' => AnalysisType::RunInsightZones,
        ];

        $resolved = [];
        foreach ($types as $key => $type) {
            $row = Analysis::query()
                ->forSubject(Activity::class, $activity->id, $type)
                ->first();
            if ($row === null || $row->status !== AnalysisStatus::Done || $row->content === null) {
                return null;
            }

            $resolved[$key] = $row->content;
        }

        return $resolved;
    }

    /**
     * LLM run-insight (gpt-5.2 with historical context), degrading to the
     * deterministic rule-based builder if the model is unavailable rather than
     * failing the whole activity group (which also holds the post-run speech).
     *
     * @return array{technical: string, splits: string, zones: string}
     */
    private function runInsights(Activity $activity, ActivityDetail $detail): array
    {
        try {
            return app(RunInsightNarrator::class)->generate($activity, $detail);
        } catch (UnavailableException|TransientUpstreamException) {
            return app(RuleBasedInsightBuilder::class)->runInsights($activity, $detail);
        }
    }
}
