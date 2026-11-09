<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\StaggerBackfillAction;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\AI\Analysis;
use Illuminate\Database\Eloquent\Builder;
use App\Models\StoryLine;
use App\Services\AI\AnalysisRowState;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class AnalyzeActivityJob extends AnalyzeGroupJob
{
    public const int WORKER_TIMEOUT_SAFETY_MARGIN_SECONDS = 10;
    public const int MINIMUM_SPEECH_DEADLINE_SECONDS = 60;

    private ?CarbonImmutable $workerDeadlineAt = null;

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

    #[Override]
    protected function onGroupHandleStarted(): void
    {
        $this->workerDeadlineAt = CarbonImmutable::now()->addSeconds(max(
            0,
            config()->integer('horizon.defaults.supervisor-ai.timeout') - self::WORKER_TIMEOUT_SAFETY_MARGIN_SECONDS,
        ));
    }

    /**
     * Chain propagation (group-level): once this activity's whole narration
     * group is Done, dispatch the next chronological activity's group for the
     * same user whose group is still Pending, via
     * requestActivityGroup(invalidate:false) — a clean no-op under a tripped cost
     * ceiling or AI-disabled env, so the chain pauses rather than breaking.
     *
     * Best-effort: any failure is logged and swallowed so it never flips this
     * already-Done, already-billed group back to Failed. The daily resume sweep
     * re-kicks any link this misses.
     */
    #[Override]
    protected function afterGroupDone(AnalysisService $service): void
    {
        try {
            $next = self::earliestPendingActivity($this->subjectId);
            if (!$next instanceof Activity) {
                return;
            }

            $service->requestActivityGroup(
                $next,
                invalidate: false,
                delaySeconds: app(StaggerBackfillAction::class)($next->user_id),
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
     * strictly later than it. Drives the chain advance and, when it is the chain
     * head, the listener kickoff and the daily resume sweep. "Pending group" is
     * matched on the representative PostRunSpeech row.
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
            ->when($after instanceof Carbon, fn ($query) => $query->where('activity_details.start_date_local', '>', $after))
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::PostRunSpeech)
                ->where('status', AnalysisStatus::Pending))
            ->orderBy('activity_details.start_date_local')
            ->select('activities.*')
            ->first();
    }

    /**
     * The user's earliest activity whose narration group is *stalled* on its
     * representative PostRunSpeech row: Pending, or Failed still under the
     * self-heal retry budget ({@see Analysis::stalled()}), so ai:self-heal can
     * retry it. Returns null when none is left.
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
    protected function fingerprintFor(mixed $subject): ?string
    {
        /** @var Activity $subject */
        return MaterialFingerprint::forActivity($subject);
    }

    #[Override]
    protected function generateAll(mixed $subject, Closure $persistGenerated): array
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

        $availableWorkerSeconds = $this->remainingWorkerSeconds() - config()->integer('azure_openai.timeout');
        if ($availableWorkerSeconds < self::MINIMUM_SPEECH_DEADLINE_SECONDS) {
            $speech = Analysis::query()
                ->forSubject(Activity::class, $subject->id, AnalysisType::PostRunSpeech)
                ->first();
            if ($speech === null) {
                throw new UnavailableException("Speech row for activity {$subject->id} missing");
            }

            app(AnalysisRowState::class)->revertToPendingWithoutAttempt($speech, $this->generationToken);

            return [];
        }

        $speechDeadlineSeconds = min(config()->integer('ai.agent.deadline_seconds'), $availableWorkerSeconds);

        return [
            AnalysisType::PostRunSpeech->value => $this->narrating(
                AnalysisType::PostRunSpeech,
                fn (): string => app(PostRunSpeechNarrator::class)->generate(
                    $subject,
                    $detail,
                    $storyLine->mood->value,
                    $speechDeadlineSeconds,
                ),
            ),
        ];
    }

    private function remainingWorkerSeconds(): int
    {
        if (!$this->workerDeadlineAt instanceof CarbonImmutable) {
            return 0;
        }

        return max(0, $this->workerDeadlineAt->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }
}
