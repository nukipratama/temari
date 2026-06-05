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
            $service->markFailed($row, $e->getMessage());
            $this->rethrowIfUnexpected($e);
        }
    }

    abstract protected function generateContent(Analysis $row): string;

    protected function discriminatorDate(Analysis $row): Carbon
    {
        return $row->discriminator !== null
            ? Carbon::parse($row->discriminator)
            : Carbon::today();
    }
}
