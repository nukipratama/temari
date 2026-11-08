<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AnalysisService
{
    /** Seconds a plan edit's re-narration waits before its job runs. */
    public const int PLAN_EDIT_DELAY_SECONDS = 30;

    public function __construct(
        private readonly AnalysisDispatcher $dispatcher,
        private readonly AnalysisRowState $rowState,
        private readonly NarrationGate $gate,
        private readonly ChainResolver $chains,
    ) {
    }

    /**
     * Suppress queue dispatch for the duration of $callback. Rows are still
     * created as Pending so a follow-up request() can dispatch them later.
     * Use for seeders or batch flows that want to stage rows first and
     * dispatch with stagger control after.
     */
    public function withoutDispatching(Closure $callback): void
    {
        $this->gate->withoutDispatching($callback);
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
        $groupJobClass = $type->groupJobClass();

        if ($groupJobClass !== null) {
            $groupDiscriminator = $groupJobClass === AnalyzeActivityJob::class ? null : $discriminator;
            $this->dispatcher->dispatchGroup($groupJobClass, $subjectId, $groupDiscriminator, $invalidate, $delaySeconds);

            return Analysis::query()
                ->forSubject($groupJobClass::subjectType(), $subjectId, $type, $groupDiscriminator)
                ->firstOrFail();
        }

        return $this->dispatcher->dispatchRow($subjectType, $subjectId, $type, $discriminator, $invalidate, $delaySeconds);
    }

    /** @see AnalysisRowState::serveRuleBased() */
    public function requestRuleBased(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
        bool $refillDone = true,
        ?AnalysisOrigin $reason = null,
    ): Analysis {
        $subjectType = $subjectOrType instanceof Model ? $subjectOrType::class : $subjectOrType;

        return $this->rowState->serveRuleBased($subjectType, $subjectId, $type, $discriminator, $refillDone, $reason);
    }

    /** @see AnalysisRowState::stagePending() */
    public function requestDeferred(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
    ): Analysis {
        $subjectType = $subjectOrType instanceof Model ? $subjectOrType::class : $subjectOrType;

        return $this->rowState->stagePending($subjectType, $subjectId, $type, $discriminator);
    }

    public function requestActivityGroup(Activity $activity, bool $invalidate = false, ?int $delaySeconds = null): void
    {
        $this->dispatcher->dispatchGroup(AnalyzeActivityJob::class, $activity->id, null, $invalidate, $delaySeconds);
    }

    /**
     * Fill the whole per-activity narration group with the deterministic
     * rule-based filler instead of dispatching a real LLM chain — for
     * activities past `ai.backfill_max_age_days`, the same loop shape as
     * {@see self::requestActivityGroupDeferred()}, filling instead of staging.
     */
    public function requestActivityGroupRuleBased(Activity $activity, ?AnalysisOrigin $reason = null): void
    {
        foreach (AnalyzeActivityJob::groupedTypes() as $type) {
            $this->requestRuleBased(AnalyzeActivityJob::subjectType(), $activity->id, $type, refillDone: false, reason: $reason);
        }
    }

    /**
     * Stage the per-activity narration group as Pending without dispatching, the
     * group analogue of {@see self::requestDeferred()}. Backfilled (old)
     * activities stage their group here so the chain narrates them one activity
     * at a time (oldest first) via the kickoff + AnalyzeActivityJob propagation,
     * rather than firing a parallel burst on ingest. The rows stay visible to the
     * UI (empty state) until the chain reaches them.
     */
    public function requestActivityGroupDeferred(Activity $activity): void
    {
        foreach (AnalyzeActivityJob::groupedTypes() as $type) {
            $this->requestDeferred(AnalyzeActivityJob::subjectType(), $activity->id, $type);
        }
    }

    public function requestBriefing(User $user, string $discriminator, bool $invalidate = false, ?int $delaySeconds = null): Analysis
    {
        return $this->dispatcher->dispatchRow(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            $discriminator,
            $invalidate,
            $delaySeconds,
        );
    }

    /**
     * Re-narrates the day's briefing after a plan edit moved a session onto or
     * off that day, unless a user-started narration of it opened the Reread
     * cooldown: then the current briefing stays, so repeated moves never bill past it.
     */
    public function rebriefAfterPlanEdit(User $user, string $date): void
    {
        $briefing = Analysis::query()
            ->forSubject(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, $date)
            ->first();

        if ($briefing?->cooldownRemaining() !== null) {
            return;
        }

        $this->requestBriefing($user, $date, invalidate: true, delaySeconds: self::PLAN_EDIT_DELAY_SECONDS);
    }

    public function requestProfileVoice(User $user, string $isoWeek, bool $invalidate = false): Analysis
    {
        return $this->request(
            subjectOrType: AnalysisType::ProfileVoice->subjectType(),
            subjectId: $user->id,
            type: AnalysisType::ProfileVoice,
            discriminator: $isoWeek,
            invalidate: $invalidate,
        );
    }

    /**
     * Request every Trends range for a user with runs, an idempotent no-op for
     * a range already requested. Shared by {@see \App\Jobs\AI\KickoffRecapsJob}
     * and {@see \App\Actions\AI\SettleEarlyNarrationAction}.
     */
    public function requestTrendReads(User $user): void
    {
        if (! Activity::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        foreach (AnalysisType::TREND_READ_RANGES as $range) {
            $this->request(
                subjectOrType: AnalysisType::TrendRead->subjectType(),
                subjectId: $user->id,
                type: AnalysisType::TrendRead,
                discriminator: $range,
            );
        }
    }

    public function isStillOpenRecapPeriod(AnalysisType $type, int $subjectId, ?string $discriminator): bool
    {
        return $this->gate->isStillOpenRecapPeriod($type, $subjectId, $discriminator);
    }

    public function shouldServeRuleBased(User $user): bool
    {
        return $this->gate->shouldServeRuleBased($user);
    }

    /**
     * Whether a chained click must resume the chain forward instead of narrating the clicked row in
     * isolation. Only a head regenerate re-narrates itself; a resumed dispatch forward-fills only
     * and must pass `invalidate: false`, or already-Done siblings are flipped back to Pending and
     * re-billed.
     */
    public function shouldResumeChain(
        User $user,
        AnalysisType $type,
        int $subjectId,
        ?string $discriminator,
        ?Analysis $existing,
    ): bool {
        return $type->isChained()
            && ! $this->chains->isHeadRegenerate($user, $type, $subjectId, $discriminator, $existing);
    }

    /**
     * Whether a manual re-trigger must first recompute the run's stream summary
     * from the already-stored streams (no Strava calls), so the regenerated
     * narration reflects the user's current zones. Only per-activity blocks carry
     * a recomputable stream summary — the weekly and monthly recaps are
     * zone-dependent too, but keyed by a snapshot/user id — and without a custom
     * profile the stored summary already used the config defaults.
     */
    public function shouldRecomputeZoneSummary(User $user, AnalysisType $type): bool
    {
        return $type->isZoneDependent()
            && $type->subjectType() === Activity::class
            && $user->runnerProfile !== null;
    }

    public function generationPaused(?int $userId = null): bool
    {
        return $this->gate->generationPaused($userId);
    }

    public function costCeilingDegraded(?int $userId = null): bool
    {
        return $this->gate->costCeilingDegraded($userId);
    }
}
