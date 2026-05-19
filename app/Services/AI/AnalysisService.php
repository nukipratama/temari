<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalysisService
{
    private bool $dispatchSuppressed = false;

    /**
     * Suppress queue dispatch for the duration of $callback. Rows are still
     * created as Pending so a follow-up request() can dispatch them later.
     * Use for seeders or batch flows that want to stage rows first and
     * dispatch with stagger control after.
     */
    public function withoutDispatching(Closure $callback): void
    {
        $previous = $this->dispatchSuppressed;
        $this->dispatchSuppressed = true;
        try {
            $callback();
        } finally {
            $this->dispatchSuppressed = $previous;
        }
    }

    public function request(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
        ?int $delaySeconds = null,
        bool $invalidate = false,
    ): Analysis {
        $subjectType = $subjectOrType instanceof Model ? $subjectOrType::class : $subjectOrType;
        $groupJobClass = $this->groupJobFor($type);

        if ($groupJobClass !== null) {
            $groupDiscriminator = $groupJobClass === AnalyzeActivityJob::class ? null : $discriminator;
            $this->dispatchGroup($groupJobClass, $subjectId, $groupDiscriminator, $invalidate, $delaySeconds);

            return Analysis::query()
                ->forSubject($groupJobClass::subjectType(), $subjectId, $type, $groupDiscriminator)
                ->firstOrFail();
        }

        return $this->dispatchRow($subjectType, $subjectId, $type, $discriminator, $invalidate, $delaySeconds);
    }

    public function requestActivityGroup(Activity $activity, bool $invalidate = false): void
    {
        $this->dispatchGroup(AnalyzeActivityJob::class, $activity->id, null, $invalidate, null);
    }

    public function requestBriefingGroup(User $user, string $discriminator, bool $invalidate = false): void
    {
        $this->dispatchGroup(AnalyzeBriefingJob::class, $user->id, $discriminator, $invalidate, null);
    }

    /**
     * @return class-string<AnalyzeGroupJob>|null
     */
    private function groupJobFor(AnalysisType $type): ?string
    {
        if (in_array($type, AnalyzeActivityJob::groupedTypes(), strict: true)) {
            return AnalyzeActivityJob::class;
        }
        if (in_array($type, AnalyzeBriefingJob::groupedTypes(), strict: true)) {
            return AnalyzeBriefingJob::class;
        }

        return null;
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

    private function dispatchRow(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator,
        bool $invalidate,
        ?int $delaySeconds,
    ): Analysis {
        $row = $this->upsertRow($subjectType, $subjectId, $type, $discriminator);
        $justCreated = $row->wasRecentlyCreated;

        if (! $this->autoDispatchEnabled()) {
            return $row;
        }

        if (! $justCreated) {
            if ($invalidate && $row->status === AnalysisStatus::Done) {
                $row->update(['status' => AnalysisStatus::Pending, 'error' => null]);
                $row->refresh();
            }

            if (! $this->rowNeedsDispatch($row)) {
                return $row;
            }

            $this->markQueued($row);
        }

        /** @var class-string<AnalyzeRowJob> $jobClass */
        $jobClass = $type->jobClass();
        $this->dispatchPending($jobClass::dispatch($row->id), $delaySeconds);

        return $row;
    }

    /**
     * @param  class-string<AnalyzeGroupJob>  $jobClass
     */
    private function dispatchGroup(
        string $jobClass,
        int $subjectId,
        ?string $discriminator,
        bool $invalidate,
        ?int $delaySeconds,
    ): void {
        $rows = $this->upsertGroupRows($jobClass::subjectType(), $subjectId, $discriminator, $jobClass::groupedTypes());
        $anyJustCreated = $rows->contains(fn (Analysis $row): bool => $row->wasRecentlyCreated);

        if (! $this->autoDispatchEnabled()) {
            return;
        }

        if ($invalidate) {
            $this->invalidateDoneRows($rows);
        }

        if (! $anyJustCreated && ! $this->groupNeedsDispatch($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (! $row->wasRecentlyCreated && $this->rowNeedsDispatch($row)) {
                $this->markQueued($row);
            }
        }

        $this->dispatchPending($jobClass::dispatch($subjectId, $discriminator), $delaySeconds);
    }

    private function upsertRow(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator,
    ): Analysis {
        $canDispatch = $this->autoDispatchEnabled();

        return Analysis::query()->firstOrCreate(
            [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'analysis_type' => $type,
                'discriminator' => $discriminator,
            ],
            [
                'status' => $canDispatch ? AnalysisStatus::Queued : AnalysisStatus::Pending,
                'queued_at' => $canDispatch ? Carbon::now() : null,
            ],
        );
    }

    /**
     * Bulk-fetch all group rows in one SELECT and insert any missing ones.
     * Returns a Collection keyed by the AnalysisType value (so callers can
     * look up by type without rescanning) in the order of $groupTypes.
     *
     * @param  array<int, AnalysisType>  $groupTypes
     * @return Collection<string, Analysis>
     */
    public function upsertGroupRows(
        string $subjectType,
        int $subjectId,
        ?string $discriminator,
        array $groupTypes,
    ): Collection {
        $typeValues = array_map(fn (AnalysisType $t): string => $t->value, $groupTypes);

        $existing = Analysis::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('discriminator', $discriminator)
            ->whereIn('analysis_type', $typeValues)
            ->get()
            ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);

        $canDispatch = $this->autoDispatchEnabled();
        $defaults = [
            'status' => $canDispatch ? AnalysisStatus::Queued : AnalysisStatus::Pending,
            'queued_at' => $canDispatch ? Carbon::now() : null,
        ];

        $rows = new Collection();
        foreach ($groupTypes as $type) {
            $row = $existing->get($type->value) ?? Analysis::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'analysis_type' => $type,
                'discriminator' => $discriminator,
                ...$defaults,
            ]);
            $rows->put($type->value, $row);
        }

        return $rows;
    }

    /** @param Collection<array-key, Analysis> $rows */
    private function invalidateDoneRows(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($row->status === AnalysisStatus::Done) {
                $row->update(['status' => AnalysisStatus::Pending, 'error' => null]);
                $row->refresh();
            }
        }
    }

    private function rowNeedsDispatch(Analysis $row): bool
    {
        return in_array(
            $row->status,
            [AnalysisStatus::Pending, AnalysisStatus::Failed],
            strict: true,
        );
    }

    /** @param Collection<array-key, Analysis> $rows */
    private function groupNeedsDispatch(Collection $rows): bool
    {
        return $rows->contains(fn (Analysis $row): bool => $this->rowNeedsDispatch($row));
    }

    private function markQueued(Analysis $row): void
    {
        $row->update([
            'status' => AnalysisStatus::Queued,
            'queued_at' => Carbon::now(),
            'error' => null,
        ]);
    }

    private function dispatchPending(PendingDispatch $pending, ?int $delaySeconds): void
    {
        $pending->onQueue($this->queueName());
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    private function autoDispatchEnabled(): bool
    {
        return ! $this->dispatchSuppressed
            && (bool) config('ai.auto_dispatch', true)
            && filled(config('azure_openai.uri'))
            && filled(config('azure_openai.api_key'));
    }

    private function queueName(): string
    {
        return (string) config('ai.queue', 'default');
    }
}
