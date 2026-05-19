<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\AnalyzeAbstractJob;
use App\Models\AI\Analysis;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AnalysisService
{
    public function __construct(private readonly Dispatcher $bus)
    {
    }

    /** @param  class-string<AnalyzeAbstractJob>|null  $jobClass */
    public function request(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $jobClass = null,
        ?string $discriminator = null,
        bool $force = false,
    ): Analysis {
        $subjectType = $subjectOrType instanceof Model
            ? $subjectOrType::class
            : $subjectOrType;
        $jobClass ??= $type->jobClass();

        $now = Carbon::now();
        $canDispatch = $force || $this->autoDispatchEnabled();
        $startStatus = $canDispatch ? AnalysisStatus::Queued : AnalysisStatus::Pending;

        $row = Analysis::query()->firstOrCreate(
            [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'analysis_type' => $type,
                'discriminator' => $discriminator,
            ],
            [
                'status' => $startStatus,
                'queued_at' => $canDispatch ? $now : null,
            ],
        );

        if (! $canDispatch) {
            return $row;
        }

        if (! $row->wasRecentlyCreated) {
            $shouldDispatch = $force || in_array(
                $row->status,
                [AnalysisStatus::Pending, AnalysisStatus::Failed],
                strict: true,
            );

            if (! $shouldDispatch) {
                return $row;
            }

            $row->update([
                'status' => AnalysisStatus::Queued,
                'queued_at' => $now,
                'error' => null,
            ]);
        }

        $this->bus->dispatch((new $jobClass($row->id))->onQueue($this->queueName()));

        return $row;
    }

    private function autoDispatchEnabled(): bool
    {
        if (! (bool) config('ai.auto_dispatch', true)) {
            return false;
        }

        return filled(config('azure_openai.uri')) && filled(config('azure_openai.api_key'));
    }

    private function queueName(): string
    {
        return (string) config('ai.queue', 'default');
    }

    public function markProcessing(Analysis $row): void
    {
        $row->update([
            'status' => AnalysisStatus::Processing,
            'attempts' => $row->attempts + 1,
        ]);
    }

    public function markDone(Analysis $row, string $content, ?string $modelVersion = null): void
    {
        $row->update([
            'status' => AnalysisStatus::Done,
            'content' => $content,
            'error' => null,
            'model_version' => $modelVersion,
            'generated_at' => Carbon::now(),
        ]);
    }

    public function markFailed(Analysis $row, string $error): void
    {
        $row->update([
            'status' => AnalysisStatus::Failed,
            'error' => $error,
        ]);
    }
}
