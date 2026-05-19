<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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

    final public function handle(AnalysisService $service): void
    {
        $row = Analysis::query()->find($this->analysisId);
        if ($row === null || $row->status === AnalysisStatus::Done) {
            return;
        }

        $service->markProcessing($row);

        try {
            $content = $this->generateContent($row);
            $service->markDone($row, $content, $this->modelVersion());
        } catch (Throwable $e) {
            $service->markFailed($row, $e->getMessage());

            if (! $e instanceof UnavailableException) {
                throw $e;
            }
        }
    }

    abstract protected function generateContent(Analysis $row): string;

    protected function modelVersion(): ?string
    {
        $deployment = config('azure_openai.deployment');

        return is_string($deployment) && $deployment !== '' ? $deployment : null;
    }

    protected function discriminatorDate(Analysis $row): Carbon
    {
        return $row->discriminator !== null
            ? Carbon::parse($row->discriminator)
            : Carbon::today();
    }

    /**
     * @return array{Activity, ActivityDetail}
     */
    protected function loadAnalyzedActivity(Analysis $row): array
    {
        $activity = Activity::query()->with('detail')->find($row->subject_id);
        if ($activity === null || $activity->detail === null) {
            throw new UnavailableException("Activity {$row->subject_id} not analyzed yet");
        }

        return [$activity, $activity->detail];
    }
}
