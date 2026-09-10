<?php

declare(strict_types=1);

namespace App\Services\Devtools;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\NarrationOrigin;
use Illuminate\Database\Eloquent\Collection;

/**
 * The two per-athlete recoveries the attention tab offers, both of them the
 * `ai:recover` mechanic narrowed to one athlete: reset the self-heal budget and
 * re-dispatch. `invalidate: false` keeps a Done sibling from being re-billed,
 * and the job-level pause guard reverts to Pending mid-cap, so neither is a way
 * to spend past a ceiling.
 */
class ReArmNarrationAction
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly NarrationOrigin $origin,
    ) {
    }

    /** Every Failed block for the athlete, whether or not its budget is spent. */
    public function retryFailed(int $userId): int
    {
        return $this->reDispatch($this->failed($userId));
    }

    /** Only the blocks that burned their retry budget and stopped auto-retrying. */
    public function reArmDeadLettered(int $userId): int
    {
        return $this->reDispatch(
            $this->failed($userId)
                ->filter(fn (Analysis $row): bool => $row->attempts >= Analysis::MAX_SELF_HEAL_ATTEMPTS),
        );
    }

    /** @return Collection<int, Analysis> */
    private function failed(int $userId): Collection
    {
        return AnalysisSubjectMap::whereOwnedBy(
            Analysis::query()->knownType()->where('status', AnalysisStatus::Failed),
            $userId,
        )->get();
    }

    /** @param iterable<int, Analysis> $rows */
    private function reDispatch(iterable $rows): int
    {
        $this->origin->set(AnalysisOrigin::Recovery);

        $count = 0;
        foreach ($rows as $row) {
            $count++;
            $row->update(['attempts' => 0]);
            $this->service->request(
                subjectOrType: $row->subject_type,
                subjectId: $row->subject_id,
                type: $row->analysis_type,
                discriminator: $row->discriminator,
                invalidate: false,
            );
        }

        return $count;
    }
}
