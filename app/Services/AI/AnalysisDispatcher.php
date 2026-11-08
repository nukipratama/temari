<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Gamification\StreakSettlementService;
use Illuminate\Foundation\Bus\PendingDispatch;

class AnalysisDispatcher
{
    public function __construct(
        private readonly AnalysisRowState $rowState,
        private readonly NarrationGate $gate,
        private readonly NarrationOrigin $origin,
        private readonly StreakSettlementService $streakSettlement,
    ) {
    }

    public function dispatchRow(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator,
        bool $invalidate,
        ?int $delaySeconds,
    ): Analysis {
        // The profile voice quotes the settled weekly streak; only the athlete's own Reread (invalidate) skips the wait.
        if ($type === AnalysisType::ProfileVoice && ! $invalidate && ! $this->streakSettlement->isSettled($subjectId)) {
            return $this->rowState->stagePending($subjectType, $subjectId, $type, $discriminator);
        }

        if ($type === AnalysisType::BriefingMascotVoice && $this->sessionSkippedOn($subjectId, $discriminator)) {
            return Analysis::query()->firstOrNew(
                [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'analysis_type' => $type,
                    'discriminator' => $discriminator,
                ],
                ['status' => AnalysisStatus::Pending],
            );
        }

        $ownerId = AnalysisSubjectMap::ownerId($subjectType, $subjectId);

        if ($this->servesOwnerRuleBased($ownerId)) {
            return $this->rowState->serveRuleBased($subjectType, $subjectId, $type, $discriminator, refillDone: $invalidate);
        }

        $row = $this->rowState->upsertRow($subjectType, $subjectId, $type, $discriminator);
        $justCreated = $row->wasRecentlyCreated;

        // Generation paused (AI off / Azure unset / broken / demo seed): stay
        // honest -> a fresh row rests Pending for the empty state, an existing
        // Done keeps its real prose, and ai:self-heal resumes it once generation
        // is back. The spend ceiling is the one pause that degrades instead.
        if (! $this->gate->autoDispatchEnabled($ownerId)) {
            if ($this->gate->costCeilingDegraded($ownerId)) {
                $this->rowState->degradeToRuleBased($row);
            }

            return $row;
        }

        if (! $justCreated && ! $this->rowState->claimForDispatch($row, $invalidate)) {
            return $row;
        }

        /** @var class-string<AnalyzeRowJob> $jobClass */
        $jobClass = $type->jobClass();
        $this->dispatchPending($this->stamped(new $jobClass($row->id, $row->generation_token)), $delaySeconds);

        return $row;
    }

    /**
     * @param  class-string<AnalyzeGroupJob>  $jobClass
     */
    public function dispatchGroup(
        string $jobClass,
        int $subjectId,
        ?string $discriminator,
        bool $invalidate,
        ?int $delaySeconds,
    ): void {
        $ownerId = AnalysisSubjectMap::ownerId($jobClass::subjectType(), $subjectId);

        if ($this->servesOwnerRuleBased($ownerId)) {
            foreach ($jobClass::groupedTypes() as $type) {
                $this->rowState->serveRuleBased($jobClass::subjectType(), $subjectId, $type, $discriminator, refillDone: $invalidate);
            }

            return;
        }

        $rows = $this->rowState->upsertGroupRows($jobClass::subjectType(), $subjectId, $discriminator, $jobClass::groupedTypes());

        if (! $this->gate->autoDispatchEnabled($ownerId)) {
            if ($this->gate->costCeilingDegraded($ownerId)) {
                foreach ($rows as $row) {
                    $this->rowState->degradeToRuleBased($row);
                }
            }

            return;
        }

        $generationToken = $this->rowState->claimGroupForDispatch(
            $jobClass::subjectType(),
            $subjectId,
            $discriminator,
            $jobClass::groupedTypes(),
            $rows,
            $invalidate,
        );

        if ($generationToken === null) {
            return;
        }

        $this->dispatchPending($this->stamped(new $jobClass($subjectId, $discriminator, $generationToken)), $delaySeconds);
    }

    /**
     * Whether a dispatch for this owner must be filled rule-based rather than
     * billed. A suppressed dispatch (the demo seed) keeps staging Pending rows,
     * which the seed fills itself.
     */
    private function servesOwnerRuleBased(?int $ownerId): bool
    {
        if ($ownerId === null || $this->gate->dispatchSuppressed()) {
            return false;
        }

        $owner = User::query()->find($ownerId);

        return $owner !== null && $this->gate->shouldServeRuleBased($owner);
    }

    /**
     * Stamp the dispatching entry point's origin onto the job, so the call it
     * eventually makes is metered against what started it rather than against
     * whichever narrator answered. The value rides the queue on the job itself;
     * {@see \App\Jobs\AI\AnalyzeBaseJob} restores it before generating.
     */
    private function stamped(AnalyzeBaseJob $job): PendingDispatch
    {
        $job->origin = $this->origin->current();

        return dispatch($job);
    }

    private function sessionSkippedOn(int $userId, ?string $date): bool
    {
        return $date !== null && PlannedSession::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('skipped', true)
            ->exists();
    }

    private function dispatchPending(PendingDispatch $pending, ?int $delaySeconds): void
    {
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }

        // Defer the actual enqueue until any surrounding DB transaction commits.
        // Without this the job could run before — or be orphaned by a rollback
        // of — the Analysis row it targets. A no-op when not in a txn.
        $pending->afterCommit();
    }
}
