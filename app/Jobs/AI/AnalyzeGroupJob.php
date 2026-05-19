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

        if ($this->allDone($rows)) {
            return;
        }

        try {
            $subject = $this->resolveSubject($this->subjectId);
        } catch (UnavailableException $e) {
            foreach ($rows as $row) {
                $service->markFailed($row, $e->getMessage());
            }

            return;
        }

        foreach ($rows as $row) {
            $service->markProcessing($row);
        }

        try {
            $payload = $this->generateAll($subject);
            $version = $this->modelVersion();

            DB::transaction(function () use ($rows, $payload, $version, $service): void {
                foreach ($rows as $key => $row) {
                    $service->markDone($row, $payload[$key], $version);
                }
            });
        } catch (Throwable $e) {
            foreach ($rows as $row) {
                $service->markFailed($row, $e->getMessage());
            }
            $this->rethrowIfUnexpected($e);
        }
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

    /** @param Collection<string, Analysis> $rows */
    private function allDone(Collection $rows): bool
    {
        return $rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done);
    }
}
