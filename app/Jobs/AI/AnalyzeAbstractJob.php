<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

abstract class AnalyzeAbstractJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 90, 240, 600];

    public function __construct(public readonly int $analysisId)
    {
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new RateLimited('ai-jobs'))->releaseAfter(60)];
    }

    final public function handle(AnalysisService $service): void
    {
        $row = Analysis::query()->find($this->analysisId);
        if ($row === null) {
            return;
        }

        if ($row->status === AnalysisStatus::Done) {
            return;
        }

        $service->markProcessing($row);

        try {
            $content = $this->generateContent($row);
            $service->markDone($row, $content, $this->modelVersion());
        } catch (UnavailableException $e) {
            $service->markFailed($row, $e->getMessage());
        } catch (Throwable $e) {
            $service->markFailed($row, $e->getMessage());
            throw $e;
        }
    }

    abstract protected function generateContent(Analysis $row): string;

    protected function modelVersion(): ?string
    {
        $deployment = config('azure_openai.deployment');

        return is_string($deployment) && $deployment !== '' ? $deployment : null;
    }
}
