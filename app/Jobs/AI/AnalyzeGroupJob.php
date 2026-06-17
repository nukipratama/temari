<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class AnalyzeGroupJob extends AnalyzeBaseJob
{
    public function __construct(
        public readonly int $subjectId,
        public readonly ?string $discriminator = null,
    ) {
    }

    final public function handle(AnalysisService $service): void
    {
        $rows = $service->upsertGroupRows(
            static::subjectType(),
            $this->subjectId,
            $this->discriminator,
            static::groupedTypes(),
        );

        $pending = $rows->filter(fn (Analysis $row): bool => $row->status !== AnalysisStatus::Done);
        if ($pending->isEmpty()) {
            return;
        }

        try {
            $subject = $this->resolveSubject($this->subjectId);
        } catch (UnavailableException $e) {
            $this->failPending($pending, $service, $e->getMessage());

            return;
        }

        foreach ($pending as $row) {
            $service->markProcessing($row);
        }

        try {
            $this->finalizePending($pending, $service, $this->generateAll($subject));
        } catch (Throwable $e) {
            $this->settleFailure(
                $e,
                markFailed: fn () => $this->failPending($pending, $service, $e->getMessage()),
                markRequeued: fn () => $pending->each(fn (Analysis $row) => $service->markQueued($row)),
            );
        }
    }

    /**
     * Last-resort hook when the worker dies (timeout / OOM / uncaught exit)
     * before `handle()` can settle the group, so rows stuck in `Processing`
     * are marked `Failed` and become re-dispatchable instead of spinning.
     */
    public function failed(Throwable $e): void
    {
        $service = app(AnalysisService::class);

        $pending = $service->upsertGroupRows(
            static::subjectType(),
            $this->subjectId,
            $this->discriminator,
            static::groupedTypes(),
        )->filter(fn (Analysis $row): bool => $row->status !== AnalysisStatus::Done
            && $row->status !== AnalysisStatus::Failed);

        $this->failPending($pending, $service, $e->getMessage());
    }

    /** @param Collection<string, Analysis> $pending */
    private function failPending(Collection $pending, AnalysisService $service, string $reason): void
    {
        foreach ($pending as $row) {
            $service->markFailed($row, $reason);
        }
    }

    /**
     * @param Collection<string, Analysis> $pending
     * @param array<string, string> $payload
     */
    private function finalizePending(Collection $pending, AnalysisService $service, array $payload): void
    {
        DB::transaction(function () use ($pending, $payload, $service): void {
            foreach ($pending as $key => $row) {
                $service->markDone($row, $payload[$key]);
            }
        });
    }

    /**
     * @return array<int, AnalysisType>
     */
    abstract public static function groupedTypes(): array;

    abstract public static function subjectType(): string;

    abstract protected function resolveSubject(int $id): mixed;

    /**
     * @return array<string, string> keyed by AnalysisType value
     */
    abstract protected function generateAll(mixed $subject): array;
}
