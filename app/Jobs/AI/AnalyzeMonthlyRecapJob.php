<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class AnalyzeMonthlyRecapJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            throw new UnavailableException("User {$row->subject_id} not found");
        }

        $month = $row->discriminator;
        if ($month === null) {
            throw new UnavailableException('MonthlyRecap requires a discriminator (Y-m).');
        }

        return app(MonthlyRecapNarrator::class)->generate($user, $month);
    }

    /**
     * Chain propagation: once this month's recap is Done, dispatch the next
     * chronological month's recap for the same user whose row is still Pending.
     * The monthly chain is keyed by the discriminator month (Y-m) under a single
     * user subject, so "next" is the smallest discriminator greater than this
     * row's month. Pre-staged Pending rows only (invalidate:false), so a tripped
     * cost ceiling or AI-disabled env makes the dispatch a clean no-op and the
     * chain pauses rather than injecting filler. This keeps each successor
     * reading its predecessor's already-Done narrative.
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
            $month = $row->discriminator;
            if ($month === null) {
                return;
            }

            $next = $this->nextPendingMonth((int) $row->subject_id, $month);
            if ($next === null) {
                return;
            }

            $service->request(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $row->subject_id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $next,
                delaySeconds: (int) config('ai.backfill_stagger_seconds', 360),
                invalidate: false,
            );
        } catch (Throwable $e) {
            Log::warning('ai.monthly_recap_chain_advance_failed', [
                'analysis_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The user's earliest month after $month (Y-m) whose MonthlyRecap row is
     * Pending. Failed/Done links are skipped: Failed waits for a manual retry or
     * the daily resume sweep, Done is already part of the story.
     */
    private function nextPendingMonth(int $userId, string $month): ?string
    {
        return Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $userId)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('status', AnalysisStatus::Pending)
            ->where('discriminator', '>', $month)
            ->orderBy('discriminator')
            ->value('discriminator');
    }
}
