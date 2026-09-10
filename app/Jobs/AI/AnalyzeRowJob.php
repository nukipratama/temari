<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\ObsoleteAnalysisException;
use App\Models\AI\Analysis;
use App\Models\AI\ContentFilterEvent;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;
use Throwable;

abstract class AnalyzeRowJob extends AnalyzeBaseJob
{
    public function __construct(public readonly int $analysisId)
    {
        parent::__construct();
    }

    final public function handle(AnalysisService $service): void
    {
        $this->applyOrigin();

        $row = Analysis::query()->find($this->analysisId);
        if ($row === null || $row->status === AnalysisStatus::Done) {
            return;
        }

        if ($this->haltForSpentRetryBudget($service, [$row])) {
            return;
        }

        if ($this->haltForPausedGeneration($service, [$row])) {
            return;
        }

        $service->markProcessing($row);

        try {
            $content = $this->generateContent($row);
            $service->markDone($row, $content, fingerprint: $this->fingerprintFor($row));
            $this->afterDone($row, $service);
        } catch (ObsoleteAnalysisException $e) {
            // The subject is gone for good, so the row describes nothing. Left
            // Failed it would sit in /ai-usage as "still auto-retrying" behind a
            // Try again that can never succeed, and burn a self-heal attempt
            // every hour proving it.
            $row->delete();
            Log::info('narrator.row.obsolete_deleted', [
                'kind' => $row->analysis_type->value,
                'subject' => $row->subject_id,
                'discriminator' => $row->discriminator,
                'reason' => $e->getMessage(),
            ]);
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
            $this->recordContentFilterFallback($row);
            $this->afterDone($row, $service);
        } catch (Throwable $e) {
            $this->settleFailure(
                $e,
                [$row],
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
     * A digest of the material this narration describes, stamped on the row so a
     * scheduled re-narration can tell an unchanged subject from a changed one and
     * skip the bill. Null (the default) means the type does not track changes and
     * its caller decides when to re-narrate.
     *
     * Deliberately not written on the rule-based paths: a filler line is not a
     * narration of the material, so a capped or content-filtered day stays
     * eligible for a real one.
     */
    protected function fingerprintFor(Analysis $row): ?string
    {
        return null;
    }

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

    /**
     * Records the fallback so its rate is visible on /devtools/ai-usage
     * (durable, analytics-schema) and on the /pulse AI pipeline card (7-day
     * trend, same mechanism as the `ai_failure` trend). Never throws: a
     * metering failure must not turn a successfully-degraded row Failed.
     */
    private function recordContentFilterFallback(Analysis $row): void
    {
        try {
            ContentFilterEvent::query()->create([
                'kind' => $row->analysis_type->value,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('content_filter_event.record_failed', [
                'kind' => $row->analysis_type->value,
                'error' => $e->getMessage(),
            ]);
        }

        Pulse::record('ai_content_filter_fallback', $row->analysis_type->value)->count();
    }
}
