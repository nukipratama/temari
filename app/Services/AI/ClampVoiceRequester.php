<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\Run\Plan\ClampNarrationContext;
use Illuminate\Support\Carbon;

final readonly class ClampVoiceRequester
{
    public function __construct(
        private AnalysisService $analysisService,
        private ClampNarrationContext $clampContext,
    ) {
    }

    /** Requests a current clamp's explanation through the shared briefing side effects. */
    public function request(User $user, Carbon $today): bool
    {
        $context = $this->clampContext->forUserOn($user->id, $today);
        if ($context === null) {
            return false;
        }

        $discriminator = $today->toDateString();
        $stamped = Analysis::query()
            ->forSubject(AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::PlanClampVoice, $discriminator)
            ->where('status', AnalysisStatus::Done)
            ->value('content_fingerprint');
        $expected = MaterialFingerprint::forClamp($context['ceiling'], $context['clamped_to'], $context['has_run_today'], $context['readiness_reasons']);

        $this->analysisService->request(
            subjectOrType: AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::PlanClampVoice,
            discriminator: $discriminator,
            invalidate: $stamped !== null && $stamped !== $expected,
        );

        return true;
    }
}
