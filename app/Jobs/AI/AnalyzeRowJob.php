<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\ContentFilterException;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class AnalyzeRowJob extends AnalyzeBaseJob
{
    public function __construct(public readonly int $analysisId)
    {
    }

    final public function handle(AnalysisService $service): void
    {
        $row = Analysis::query()->find($this->analysisId);
        if ($row === null || $row->status === AnalysisStatus::Done) {
            return;
        }

        if ($this->haltForPausedGeneration($service, [$row])) {
            return;
        }

        $service->markProcessing($row);

        try {
            $content = $this->generateContent($row);
            $service->markDone($row, $content);
            $this->afterDone($row, $service);
        } catch (ContentFilterException) {
            // The continuity-stripped retry still content-filtered. Degrade to
            // rule-based content instead of dead-lettering: the user gets a
            // benign line, and (for chained narrators) that benign line becomes
            // the next prev_narrative, breaking the poison loop at its source.
            $service->markDone($row, app(RuleBasedNarrationFiller::class)->fillFor($row));
            Log::info('narrator.ai.content_filter_fallback', [
                'kind' => $row->analysis_type->value,
                'subject' => $row->subject_id,
            ]);
            $this->afterDone($row, $service);
        } catch (Throwable $e) {
            $this->settleFailure(
                $e,
                markFailed: fn () => $service->markFailed($row, $e->getMessage()),
                markRequeued: fn () => $service->markQueued($row),
            );
        }
    }

    /**
     * Last-resort hook when the worker dies (timeout / OOM / uncaught exit)
     * before `handle()` can settle the row, so a row stuck in `Processing` is
     * marked `Failed` and becomes re-dispatchable instead of spinning forever.
     */
    public function failed(Throwable $e): void
    {
        $row = Analysis::query()->find($this->analysisId);
        if ($row === null
            || $row->status === AnalysisStatus::Done
            || $row->status === AnalysisStatus::Failed) {
            return;
        }

        app(AnalysisService::class)->markFailed($row, $e->getMessage());
    }

    abstract protected function generateContent(Analysis $row): string;

    /**
     * Hook fired after a row is marked Done. Connected + chained narrators
     * override this to dispatch the next chronological link in their chain
     * (predecessor-Done-before-successor). No-op by default, so standalone
     * narrators keep their independent per-row behavior.
     */
    protected function afterDone(Analysis $row, AnalysisService $service): void
    {
        //
    }

    protected function discriminatorDate(Analysis $row): Carbon
    {
        return $row->discriminator !== null
            ? Carbon::parse($row->discriminator)
            : Carbon::today();
    }
}
