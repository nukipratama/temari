<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Enums\SessionType;
use App\Exceptions\AI\ObsoleteAnalysisException;
use App\Models\AI\Analysis;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\Narrators\PlanClampVoiceNarrator;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\ClampNarrationContext;

/**
 * Narrates a readiness clamp, and only ever a clamp: the row is requested from
 * the two places that already compute a {@see \App\Services\Run\Metrics\ReadinessCeiling}
 * — the ingest listener and the 00:01 briefing — never from a render.
 *
 * A clamp cannot be reconstructed after the fact (the ceiling counts the day's
 * own runs, so the one that fired at 08:00 is gone by midnight), so a row whose
 * day no longer clamps is obsolete rather than failed: the athlete recovered,
 * or the plan moved, and nothing will ever fill it.
 */
class AnalyzePlanClampVoiceJob extends AnalyzeRowJob
{
    /** @var array{ceiling: ReadinessCeiling, original: SessionType, clamped_to: SessionType, has_run_today: bool}|null */
    private ?array $context = null;

    protected function generateContent(Analysis $row): string
    {
        return app(PlanClampVoiceNarrator::class)->generate($this->contextFor($row), (int) $row->subject_id);
    }

    protected function fingerprintFor(Analysis $row): ?string
    {
        $context = $this->contextFor($row);

        return MaterialFingerprint::forClamp($context['ceiling'], $context['clamped_to'], $context['has_run_today']);
    }

    /**
     * Memoized: generateContent() resolves it, fingerprintFor() reads it back.
     *
     * @return array{ceiling: ReadinessCeiling, original: SessionType, clamped_to: SessionType, has_run_today: bool}
     */
    private function contextFor(Analysis $row): array
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $context = app(ClampNarrationContext::class)->forUserOn(
            (int) $row->subject_id,
            $this->discriminatorDate($row),
        );

        if ($context === null) {
            throw new ObsoleteAnalysisException(
                "No readiness clamp for user {$row->subject_id} on {$this->discriminatorDate($row)->toDateString()}",
            );
        }

        return $this->context = $context;
    }
}
