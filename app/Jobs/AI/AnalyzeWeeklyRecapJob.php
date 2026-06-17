<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class AnalyzeWeeklyRecapJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $snapshot = WeeklySnapshot::query()->find($row->subject_id);
        if ($snapshot === null) {
            throw new UnavailableException("WeeklySnapshot {$row->subject_id} not found");
        }

        return app(WeeklyRecapNarrator::class)->generate($snapshot);
    }

    /**
     * Chain propagation: once this week's recap is Done, dispatch the next
     * chronological week's recap for the same user whose row is still Pending.
     * Pre-staged Pending rows only (invalidate:false), so a tripped cost ceiling
     * or AI-disabled env makes the dispatch a clean no-op and the chain pauses
     * rather than injecting filler. This keeps each successor reading its
     * predecessor's already-Done narrative.
     *
     * Best-effort: any failure here (DB blip, dispatch error) is logged and
     * swallowed so it never flips this already-Done, already-billed row back to
     * Failed (which would re-bill on retry). The daily resume sweep re-kicks any
     * link this misses.
     */
    #[Override]
    protected function afterDone(Analysis $row, AnalysisService $service): void
    {
        try {
            $snapshot = WeeklySnapshot::query()->find($row->subject_id);
            if ($snapshot === null) {
                return;
            }

            $next = $this->nextPendingSnapshot($snapshot);
            if ($next === null) {
                return;
            }

            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $next->id,
                type: AnalysisType::WeeklyRecap,
                delaySeconds: (int) config('ai.backfill_stagger_seconds', 360),
                invalidate: false,
            );
        } catch (Throwable $e) {
            Log::warning('ai.weekly_recap_chain_advance_failed', [
                'analysis_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The user's earliest week after $current whose WeeklyRecap row is Pending
     * (runs > 0). Failed/Done links are skipped: Failed waits for a manual retry
     * or the daily resume sweep, Done is already part of the story.
     */
    private function nextPendingSnapshot(WeeklySnapshot $current): ?WeeklySnapshot
    {
        return WeeklySnapshot::query()
            ->where('user_id', $current->user_id)
            ->where('week_ending', '>', $current->week_ending)
            ->where('runs', '>', 0)
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Pending))
            ->orderBy('week_ending')
            ->first();
    }
}
