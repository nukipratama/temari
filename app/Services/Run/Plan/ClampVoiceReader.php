<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

final readonly class ClampVoiceReader
{
    public function __construct(private ClampNarrationContext $clampContext)
    {
    }

    /**
     * The narrated explanation for today's step-down, or null while none has
     * landed. Callers fall back to the clamp's own templated note, which is why
     * this returns only a Done row and never a pending one.
     */
    public function clampVoiceFor(User $user, Carbon $today): ?string
    {
        $context = $this->clampContext->forUserOn($user->id, $today);
        if ($context === null) {
            return null;
        }

        $analysis = Analysis::query()
            ->forSubject(AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::PlanClampVoice, $today->toDateString())
            ->where('status', AnalysisStatus::Done)
            ->first(['content', 'content_fingerprint']);
        if ($analysis === null) {
            return null;
        }

        $expected = ClampNarrationContext::fingerprint($context['ceiling'], $context['clamped_to'], $context['has_run_today'], $context['readiness_reasons']);

        return $analysis->content_fingerprint === $expected ? $analysis->content : null;
    }
}
