<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Jobs\AI\RecheckEffortScoreNarrationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;

/**
 * Re-narrates a run once its first effort score lands, delayed by
 * {@see AnalysisService::PLAN_EDIT_DELAY_SECONDS} so quick corrections merge into
 * one job. The fingerprint records only whether a score exists, so editing the
 * score, or removing and logging it again, matches the narration already
 * stamped and requests nothing. The demo athlete is never narrated.
 */
final readonly class RenarrateAfterEffortScore
{
    public function __construct(private AnalysisService $analysisService)
    {
    }

    public function __invoke(Activity $activity, bool $recheck = true): void
    {
        if ($activity->user->is_demo) {
            return;
        }

        $speech = Analysis::query()
            ->forSubject(Activity::class, $activity->id, AnalysisType::PostRunSpeech)
            ->first();
        if ($speech?->status === AnalysisStatus::Processing && $recheck) {
            RecheckEffortScoreNarrationJob::dispatch($activity->id)->delay(config()->integer('horizon.defaults.supervisor-ai.timeout'));

            return;
        }

        if ($speech?->status !== AnalysisStatus::Done
            || $speech->content_fingerprint === null
            || $speech->content_fingerprint === MaterialFingerprint::forActivity($activity)) {
            return;
        }

        $this->analysisService->requestActivityGroup($activity, invalidate: true, delaySeconds: AnalysisService::PLAN_EDIT_DELAY_SECONDS);
    }
}
