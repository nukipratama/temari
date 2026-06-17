<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use Illuminate\Support\Carbon;
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

        $service->markProcessing($row);

        try {
            $content = $this->generateContent($row);
            $service->markDone($row, $content);
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

    protected function discriminatorDate(Analysis $row): Carbon
    {
        return $row->discriminator !== null
            ? Carbon::parse($row->discriminator)
            : Carbon::today();
    }
}
