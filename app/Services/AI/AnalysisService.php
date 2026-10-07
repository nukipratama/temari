<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use RuntimeException;
use App\Enums\FeedbackSubject;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\Feedback;
use App\Models\RunCard;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\Gamification\StreakSettlementService;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;

class AnalysisService
{
    public function __construct(
        private readonly NarrationGate $gate,
        private readonly NotificationEligibility $eligibility,
        private readonly MaintainerAlerter $alerter,
        private readonly ChainResolver $chains,
        private readonly CostCeilingLedger $ceilingLedger,
        private readonly NarrationOrigin $origin,
        private readonly HistoryNarrationGate $history,
        private readonly StreakSettlementService $streakSettlement,
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
            $this->dispatchGroup($groupJobClass, $subjectId, $groupDiscriminator, $invalidate, $delaySeconds);

            return Analysis::query()
                ->forSubject($groupJobClass::subjectType(), $subjectId, $type, $groupDiscriminator)
                ->firstOrFail();
        }

        return $this->dispatchRow($subjectType, $subjectId, $type, $discriminator, $invalidate, $delaySeconds);
    }

    /**
     * Serve a trigger from the deterministic rule-based filler instead of the
     * LLM. The row is reused (or staged) and marked Done immediately, all under
     * {@see self::withoutDispatching()}, so no job is queued, no cooldown starts
     * and no notification fans out. The demo login is public and a manual
     * trigger deliberately fires past the cost ceiling, so the demo account's
     * "Reread" resolves here and can never bill Azure.
     *
     * $refillDone controls whether an already-Done row gets overwritten: the
     * demo "Reread" trigger wants true (its content is rule-based to begin
     * with, so refilling in place is a no-op it can rely on), but a caller
     * filling in a too-old-for-the-LLM backfill row must pass false — that row
     * can legitimately already hold real, billed-for narration (e.g. a Strava
     * resync of an activity that aged past the backfill cap since it was first
     * narrated), which must never be silently clobbered with filler prose.
     *
     * $reason records why on the row itself ({@see Analysis::$rule_based_reason})
     * beyond "some rule-based filler wrote this" — today only ever passed as
     * {@see AnalysisOrigin::Return} by {@see \App\Jobs\AI\NarrateOnReturnJob}'s
     * own catch-up calls, never left to whatever {@see NarrationOrigin} happens
     * to be ambient, so a cost-ceiling degrade mid-return chain is never
     * mistaken for an away-fill.
     */
    public function requestRuleBased(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
        bool $refillDone = true,
        ?AnalysisOrigin $reason = null,
    ): Analysis {
        $row = $this->requestDeferred($subjectOrType, $subjectId, $type, $discriminator);

        if (! $refillDone && $row->status === AnalysisStatus::Done) {
            return $row;
        }

        $this->fillRuleBased($row, $reason);

        return $row;
    }

    /**
     * Upsert the Analysis row as Pending without dispatching, filling, or
     * invalidating. For windowed cadences (weekly/monthly) the LLM generation
     * is deferred to the scheduled command that fires once the window closes,
     * instead of re-billing the narration on every ingest inside the window.
     * The row stays visible to the UI (empty state + manual "Reread").
     */
    public function requestDeferred(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
    ): Analysis {
        $subjectType = $subjectOrType instanceof Model ? $subjectOrType::class : $subjectOrType;

        return Analysis::query()->firstOrCreate(
            [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'analysis_type' => $type,
                'discriminator' => $discriminator,
            ],
            ['status' => AnalysisStatus::Pending],
        );
    }

    public function requestActivityGroup(Activity $activity, bool $invalidate = false, ?int $delaySeconds = null): void
    {
        $this->dispatchGroup(AnalyzeActivityJob::class, $activity->id, null, $invalidate, $delaySeconds);
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
        return $this->dispatchRow(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            $discriminator,
            $invalidate,
            $delaySeconds,
        );
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

    /** @return Builder<Analysis> */
    private function generationQuery(Analysis $row, ?string $generationToken): Builder
    {
        $query = Analysis::query()->whereKey($row->getKey());

        return $generationToken === null
            ? $query->whereNull('generation_token')
            : $query->where('generation_token', $generationToken);
    }

    public function markProcessing(
        Analysis $row,
        ?string $generationToken = null,
        bool $allowProcessing = false,
    ): bool {
        $generationToken ??= $row->generation_token;
        $eligibleStatuses = [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Failed];
        if ($allowProcessing) {
            $eligibleStatuses[] = AnalysisStatus::Processing;
        }

        $updated = $this->generationQuery($row, $generationToken)
            ->whereIn('status', $eligibleStatuses)
            ->update([
                'status' => AnalysisStatus::Processing,
                'attempts' => DB::raw('attempts + 1'),
            ]) === 1;

        if ($updated) {
            $row->forceFill([
                'status' => AnalysisStatus::Processing,
                'attempts' => $row->attempts + 1,
            ])->syncOriginal();
        }

        return $updated;
    }

    /** @param Collection<string, Analysis> $rows */
    public function markGroupProcessing(Collection $rows, ?string $generationToken = null, bool $allowProcessing = false): bool
    {
        $rows = $rows->values();
        if ($rows->isEmpty()) {
            return false;
        }

        $generationToken ??= $rows->first()->generation_token;
        $eligibleStatuses = [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Failed];
        if ($allowProcessing) {
            $eligibleStatuses[] = AnalysisStatus::Processing;
        }

        return DB::transaction(function () use ($rows, $generationToken, $eligibleStatuses): bool {
            $query = Analysis::query()->whereKey($rows->map(fn (Analysis $row): int => $row->id)->all());
            $query = $generationToken === null
                ? $query->whereNull('generation_token')
                : $query->where('generation_token', $generationToken);
            $currentRows = (clone $query)->orderBy('id')->lockForUpdate()->get();

            if ($currentRows->count() !== $rows->count()
                || ! $currentRows->every(fn (Analysis $row): bool => in_array($row->status, $eligibleStatuses, true))) {
                return false;
            }

            $updated = $query->whereIn('status', $eligibleStatuses)->update([
                'status' => AnalysisStatus::Processing,
                'attempts' => DB::raw('attempts + 1'),
            ]);
            if ($updated !== $rows->count()) {
                throw new RuntimeException('Could not atomically claim every narration row');
            }

            $attemptsById = [];
            foreach ($currentRows as $currentRow) {
                $attemptsById[$currentRow->id] = $currentRow->attempts;
            }

            foreach ($rows as $row) {
                $attempts = $attemptsById[$row->id] ?? null;
                if ($attempts === null) {
                    throw new RuntimeException('Could not find a claimed narration row');
                }

                $row->forceFill([
                    'status' => AnalysisStatus::Processing,
                    'attempts' => $attempts + 1,
                ])->syncOriginal();
            }

            return true;
        });
    }

    public function markDone(
        Analysis $row,
        string $content,
        ServedBy $servedBy,
        ?Carbon $generatedAt = null,
        ?string $fingerprint = null,
        ?AnalysisOrigin $ruleBasedReason = null,
        bool $startedEarly = false,
        ?string $generationToken = null,
    ): bool {
        $generationToken ??= $row->generation_token;

        return DB::transaction(function () use ($row, $content, $servedBy, $generatedAt, $fingerprint, $ruleBasedReason, $startedEarly, $generationToken): bool {
            $current = $this->generationQuery($row, $generationToken)->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }

            $this->archivePreviousVersion($current);
            $this->supersedeFeedback($current);

            // Only an LLM serve can be "early". $isEarlyNow is the live check;
            // a row that started early but finished after the drain emptied
            // (straddled) is not marked, since the replay that would ever
            // regenerate it has already run — it re-requests itself instead.
            $isEarlyNow = $servedBy === ServedBy::Llm && $this->isEarlyPassRow($current);
            $straddled = $servedBy === ServedBy::Llm && $startedEarly && ! $isEarlyNow;

            $current->update([
                'status' => AnalysisStatus::Done,
                'content' => $content,
                'error' => null,
                'served_by' => $servedBy,
                // Only a rule-based fill ever carries a reason, and only when its
                // caller declares one; an LLM serve always clears it.
                'rule_based_reason' => $servedBy === ServedBy::RuleBased ? $ruleBasedReason : null,
                'generated_at' => $generatedAt ?? Carbon::now(),
                'stale_at' => null,
                // Only per-run activity groups pass a fingerprint; other types
                // write the existing value back untouched.
                'content_fingerprint' => $fingerprint ?? $current->content_fingerprint,
                // Cleared by an ordinary re-narration once history has landed —
                // see SettleEarlyNarrationAction.
                'narrated_early_at' => $isEarlyNow ? Carbon::now() : null,
            ]);
            $row->setRawAttributes($current->getAttributes());
            $row->syncOriginal();

            // Skipped under withoutDispatching (demo seed) so a freshly seeded demo
            // stays instantly re-narratable on demand. afterCommit: AnalyzeGroupJob
            // wraps several markDone() calls in one DB::transaction(), and the
            // Redis-backed cooldown isn't rolled back by a transaction abort, so
            // starting it eagerly could cool a row whose Done status never committed.
            if (! $this->gate->dispatchSuppressed()) {
                DB::afterCommit(fn () => $row->startCooldown());
            }

            if ($straddled) {
                // The straddling row's own content may have read a history that
                // finished landing mid-generation; ask for it once more now,
                // rather than trust the replay that already ran without it.
                if (! $this->gate->dispatchSuppressed()) {
                    DB::afterCommit(fn () => $this->request(
                        $row->subject_type,
                        $row->subject_id,
                        $row->analysis_type,
                        $row->discriminator,
                        invalidate: true,
                    ));
                }
            } elseif (! $this->gate->dispatchSuppressed()
                && ! $isEarlyNow
                && $this->origin->current() !== AnalysisOrigin::Return
                && $this->eligibility->isNotifiable($row)) {
                // Suppressed while early: every channel's delivery claim is keyed
                // on this row's id for good, so notifying now would permanently
                // spend it on a run that cannot yet know whether it set a PR —
                // the replay's regeneration is the row's one real send.
                $this->eligibility->resolveUser($row)?->notify(
                    new AnalysisReadyNotification($row)->afterCommit(),
                );
            }

            return true;
        });
    }

    public function deleteObsoleteRow(Analysis $row, ?string $generationToken = null): bool
    {
        $generationToken ??= $row->generation_token;

        return DB::transaction(function () use ($row, $generationToken): bool {
            $current = $this->generationQuery($row, $generationToken)
                ->where('status', AnalysisStatus::Processing)
                ->lockForUpdate()
                ->first();

            return $current?->delete() ?? false;
        });
    }

    /**
     * Whether $row is being narrated during a fresh connect's early pass: its
     * own reference date still has older history (or, for the whole-history
     * types, any history at all) awaiting hydration. Evaluated live against
     * current hydration state, so a row that generates after the drain has
     * already finished is never marked — it simply reads the real thing.
     */
    public function isEarlyPassRow(Analysis $row): bool
    {
        return match ($row->analysis_type) {
            AnalysisType::PostRunSpeech, AnalysisType::RunInsight => $this->earlyPassForActivity(
                Activity::query()->with('detail')->find($row->subject_id),
            ),
            AnalysisType::CardFlavor => $this->earlyPassForActivity(
                RunCard::query()->with('activity.detail')->find($row->subject_id)?->activity,
            ),
            AnalysisType::BriefingMascotVoice => $this->history->awaitsOlderHydration($row->subject_id, Carbon::now()),
            AnalysisType::ProfileVoice => $this->history->awaitsFullHydration($row->subject_id),
            default => false,
        };
    }

    private function earlyPassForActivity(?Activity $activity): bool
    {
        $startedAt = $activity?->detail?->start_date_local;

        return $activity !== null && $startedAt !== null
            && $this->history->awaitsOlderHydration($activity->user_id, $startedAt);
    }

    /**
     * Keep the narration this row is about to lose. A row with no content has
     * never been narrated, so there is nothing to supersede and no version is
     * written.
     */
    private function archivePreviousVersion(Analysis $row): void
    {
        if ($row->content === null) {
            return;
        }

        AnalysisVersion::query()->create([
            'analysis_id' => $row->id,
            'content' => $row->content,
            'fingerprint' => $row->content_fingerprint,
            'served_by' => $row->served_by,
            'generated_at' => $row->generated_at,
        ]);
    }

    /**
     * Retire the flags filed against the narration this row is about to lose,
     * so the athlete's "this is wrong" stops standing against text that has
     * been replaced and the control comes back for the new one. A row with no
     * content has never been narrated, so nothing has been flagged yet.
     */
    private function supersedeFeedback(Analysis $row): void
    {
        if ($row->content === null) {
            return;
        }

        Feedback::query()
            ->where('subject_type', FeedbackSubject::Narration)
            ->where('subject_id', $row->id)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => Carbon::now()]);
    }

    public function markFailed(Analysis $row, string $error, ?string $generationToken = null): void
    {
        $generationToken ??= $row->generation_token;
        $updated = $this->generationQuery($row, $generationToken)->update([
            'status' => AnalysisStatus::Failed,
            'error' => $error,
        ]);
        if ($updated !== 1) {
            return;
        }

        $row->forceFill(['status' => AnalysisStatus::Failed, 'error' => $error])->syncOriginal();

        // Feed the /pulse AI Pipeline-health card's failure-rate trend.
        Pulse::record('ai_failure', $row->analysis_type->value)->count();

        // Push a maintainer alert exactly at the dead-letter crossing: attempts is
        // bumped once per real run (markProcessing), so it reaches MAX only on the
        // final failing attempt, making this fire once per dead-letter, not per
        // failed attempt. A manual re-arm (attempts -> 0) re-opens the budget, so a
        // later re-exhaustion is a genuine new dead-letter and alerts again.
        if ($row->attempts >= Analysis::MAX_SELF_HEAL_ATTEMPTS) {
            $this->alerter->deadLettered();
        }
    }

    private function dispatchRow(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator,
        bool $invalidate,
        ?int $delaySeconds,
    ): Analysis {
        // The profile voice quotes the settled weekly streak; only the athlete's own Reread (invalidate) skips the wait.
        if ($type === AnalysisType::ProfileVoice && ! $invalidate && ! $this->streakSettlement->isSettled($subjectId)) {
            return $this->requestDeferred($subjectType, $subjectId, $type, $discriminator);
        }

        $row = $this->upsertRow($subjectType, $subjectId, $type, $discriminator);
        $justCreated = $row->wasRecentlyCreated;

        // Generation paused (AI off / Azure unset / broken / demo seed): stay
        // honest -> a fresh row rests Pending for the empty state, an existing
        // Done keeps its real prose, and ai:self-heal resumes it once generation
        // is back. The spend ceiling is the one pause that degrades instead.
        $ownerId = AnalysisSubjectMap::ownerId($subjectType, $subjectId);

        if (! $this->gate->autoDispatchEnabled($ownerId)) {
            if ($this->gate->costCeilingDegraded($ownerId)) {
                $this->degradeToRuleBased($row);
            }

            return $row;
        }

        if (! $justCreated) {
            $claimed = DB::transaction(function () use ($row, $invalidate): bool {
                if ($invalidate) {
                    $this->invalidateDoneRow($row);
                }

                return $this->claimForDispatch($row);
            });

            if (! $claimed) {
                return $row;
            }
        }

        /** @var class-string<AnalyzeRowJob> $jobClass */
        $jobClass = $type->jobClass();
        $this->dispatchPending($this->stamped(new $jobClass($row->id, $row->generation_token)), $delaySeconds);

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
        $ownerId = AnalysisSubjectMap::ownerId($jobClass::subjectType(), $subjectId);

        if (! $this->gate->autoDispatchEnabled($ownerId)) {
            if ($this->gate->costCeilingDegraded($ownerId)) {
                foreach ($rows as $row) {
                    $this->degradeToRuleBased($row);
                }
            }

            return;
        }

        $generationToken = DB::transaction(function () use ($jobClass, $subjectId, $discriminator, $rows, $invalidate): ?string {
            $typeValues = array_map(fn (AnalysisType $type): string => $type->value, $jobClass::groupedTypes());
            $groupRows = Analysis::query()
                ->where('subject_type', $jobClass::subjectType())
                ->where('subject_id', $subjectId)
                ->where('discriminator', $discriminator)
                ->whereIn('analysis_type', $typeValues)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($groupRows->count() !== count($typeValues)
                || $groupRows->contains(fn (Analysis $row): bool => in_array($row->status, [AnalysisStatus::Queued, AnalysisStatus::Processing], true))) {
                return null;
            }

            if ($invalidate) {
                foreach ($rows as $row) {
                    $this->invalidateDoneRow($row);
                }

                $groupRows = Analysis::query()
                    ->where('subject_type', $jobClass::subjectType())
                    ->where('subject_id', $subjectId)
                    ->where('discriminator', $discriminator)
                    ->whereIn('analysis_type', $typeValues)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $claimable = $groupRows->filter(fn (Analysis $row): bool => in_array($row->status, [AnalysisStatus::Pending, AnalysisStatus::Failed], true));
            if ($claimable->isEmpty()) {
                return null;
            }

            $generationToken = (string) Str::uuid();
            $now = Carbon::now();

            Analysis::query()
                ->whereIn('id', $groupRows->modelKeys())
                ->update(['generation_token' => $generationToken]);

            $claimed = Analysis::query()
                ->whereIn('id', $claimable->modelKeys())
                ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Failed])
                ->update([
                    'status' => AnalysisStatus::Queued,
                    'generation_token' => $generationToken,
                    'queued_at' => $now,
                    'error' => null,
                ]);

            return $claimed === $claimable->count() ? $generationToken : null;
        });

        if ($generationToken === null) {
            return;
        }

        $this->dispatchPending($this->stamped(new $jobClass($subjectId, $discriminator, $generationToken)), $delaySeconds);
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

    private function upsertRow(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator,
    ): Analysis {
        $canDispatch = $this->gate->autoDispatchEnabled();

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
                'generation_token' => $canDispatch ? (string) Str::uuid() : null,
            ],
        );
    }

    /**
     * Fetch all group rows and insert any missing ones. Rows this call actually
     * inserted carry `wasRecentlyCreated`; concurrent inserts do not.
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

        $existing = $this->fetchGroupRows($subjectType, $subjectId, $discriminator, $typeValues);

        $missingValues = array_values(array_filter(
            $typeValues,
            fn (string $value): bool => ! $existing->has($value),
        ));

        /** @var Collection<string, Analysis> $inserted */
        $inserted = $missingValues === []
            ? new Collection()
            : $this->insertGroupRows($subjectType, $subjectId, $discriminator, $missingValues);

        /** @var Collection<string, Analysis> $rows */
        $rows = new Collection();
        foreach ($typeValues as $value) {
            $row = $existing->get($value) ?? $inserted->get($value);
            if ($row instanceof Analysis) {
                $rows->put($value, $row);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $typeValues
     * @return Collection<string, Analysis>
     */
    private function fetchGroupRows(
        string $subjectType,
        int $subjectId,
        ?string $discriminator,
        array $typeValues,
    ): Collection {
        return Analysis::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('discriminator', $discriminator)
            ->whereIn('analysis_type', $typeValues)
            ->get()
            ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);
    }

    /**
     * INSERT IGNORE dedupes against the ai_analyses unique index over the stored
     * `discriminator_key` generated column, so a concurrent creator collapses to
     * the same row. It bypasses Eloquent, hence the explicit timestamps and enum
     * values. Insert one type at a time so the affected-row result identifies
     * exactly which rows this caller created.
     *
     * @param  array<int, string>  $typeValues
     * @return Collection<string, Analysis>
     */
    private function insertGroupRows(
        string $subjectType,
        int $subjectId,
        ?string $discriminator,
        array $typeValues,
    ): Collection {
        $now = Carbon::now();
        $createdValues = [];

        foreach ($typeValues as $value) {
            $inserted = Analysis::query()->insertOrIgnore([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'analysis_type' => $value,
                'discriminator' => $discriminator,
                'status' => AnalysisStatus::Pending->value,
                'queued_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                $createdValues[] = $value;
            }
        }

        return $this->fetchGroupRows($subjectType, $subjectId, $discriminator, $typeValues)
            ->each(function (Analysis $row) use ($createdValues): void {
                $row->wasRecentlyCreated = in_array($row->analysis_type->value, $createdValues, true);
            });
    }

    /**
     * Send a Done row back to Pending so the next dispatch re-narrates it.
     *
     * Only an invalidation the athlete asked for ("Reread", a plan edit, a
     * replan) also re-arms the self-heal budget, read from the origin its entry
     * point already declares: `attempts` counts real LLM executions per row, and
     * a system invalidation fires on repeatable events (an ingest, the Monday
     * fingerprint sweep), so resetting there would make MAX_SELF_HEAL_ATTEMPTS
     * a bound per invalidation rather than per row.
     */
    private function invalidateDoneRow(Analysis $row): void
    {
        if ($row->status !== AnalysisStatus::Done) {
            return;
        }

        $attributes = [
            'status' => AnalysisStatus::Pending,
            'error' => null,
            ...($this->origin->current() === AnalysisOrigin::User ? ['attempts' => 0] : []),
        ];
        $updated = $this->generationQuery($row, $row->generation_token)
            ->where('status', AnalysisStatus::Done)
            ->update($attributes);

        if ($updated === 1) {
            $row->forceFill($attributes)->syncOriginal();
        }
    }

    /**
     * Claim a row for dispatch: one conditional UPDATE that both asks whether
     * the row may be dispatched and takes it, so two dispatchers reading the
     * same Pending row (a UI retry racing self-heal, a resync racing a retry)
     * cannot both enqueue and both bill Azure. Returns whether this caller won.
     */
    private function claimForDispatch(Analysis $row): bool
    {
        $now = Carbon::now();
        $generationToken = (string) Str::uuid();

        $claimed = $this->generationQuery($row, $row->generation_token)
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Failed])
            ->update([
                'status' => AnalysisStatus::Queued,
                'generation_token' => $generationToken,
                'queued_at' => $now,
                'error' => null,
            ]) === 1;

        if ($claimed) {
            $row->forceFill([
                'status' => AnalysisStatus::Queued,
                'generation_token' => $generationToken,
                'queued_at' => $now,
                'error' => null,
            ])->syncOriginal();
        }

        return $claimed;
    }

    public function markQueued(Analysis $row, ?string $generationToken = null): void
    {
        $generationToken ??= $row->generation_token;
        $attributes = [
            'status' => AnalysisStatus::Queued,
            'queued_at' => Carbon::now(),
            'error' => null,
        ];
        if ($this->generationQuery($row, $generationToken)->update($attributes) === 1) {
            $row->forceFill($attributes)->syncOriginal();
        }
    }

    /** Send a row back to Pending when a paused or stale in-flight run resumes later. */
    public function revertToPending(Analysis $row, ?string $generationToken = null): void
    {
        $generationToken ??= $row->generation_token;
        $attributes = [
            'status' => AnalysisStatus::Pending,
            'queued_at' => null,
        ];
        if ($this->generationQuery($row, $generationToken)
            ->where('status', '!=', AnalysisStatus::Done)
            ->update($attributes) === 1) {
            $row->forceFill($attributes)->syncOriginal();
        }
    }

    /** Refund an attempt only when speech is deferred before its narrator starts. */
    public function revertToPendingWithoutAttempt(Analysis $row, ?string $generationToken = null): void
    {
        $generationToken ??= $row->generation_token;
        $updated = $this->generationQuery($row, $generationToken)
            ->where('status', AnalysisStatus::Processing)
            ->update([
                'status' => AnalysisStatus::Pending,
                'queued_at' => null,
                'attempts' => DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END'),
            ]) === 1;

        if ($updated) {
            $row->forceFill([
                'status' => AnalysisStatus::Pending,
                'queued_at' => null,
                'attempts' => max(0, $row->attempts - 1),
            ])->syncOriginal();
        }
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

    public function isStillOpenRecapPeriod(AnalysisType $type, int $subjectId, ?string $discriminator): bool
    {
        return $this->gate->isStillOpenRecapPeriod($type, $subjectId, $discriminator);
    }

    public function shouldServeRuleBased(User $user): bool
    {
        return $this->gate->shouldServeRuleBased($user);
    }

    /**
     * Whether a chained click must resume the chain forward instead of narrating
     * the clicked row in isolation. Only a head regenerate (a Done row that IS
     * the chain head) re-narrates itself; every other chained click, including a
     * Done mid-history row reached by a hand-crafted POST, resumes, so
     * re-narrating mid-history never desyncs the later blocks that quoted its old
     * narrative. A resumed dispatch forward-fills only and must pass
     * `invalidate: false`, or already-Done siblings of the resumed group are
     * flipped back to Pending and re-billed.
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

    /**
     * Serve a row from the deterministic filler because the spend ceiling is
     * hit. Filled under {@see self::withoutDispatching()} so no job is queued, no
     * cooldown starts and no notification claims a narration that was never
     * written.
     *
     * Two statuses are left alone. An already-Done row keeps the real prose it
     * was billed for. A Failed row is a genuine fault (content filter, malformed
     * response, spent retry budget) that the bounded self-heal and the /devtools/narration
     * dead-letter exist to surface, so it stays Failed with its "Try again"
     * rather than hiding a break behind plausible content — on a day the ceiling
     * trips repeatedly, filling it would erase that signal every time.
     */
    public function degradeToRuleBased(Analysis $row, ?string $generationToken = null): void
    {
        if ($row->status === AnalysisStatus::Done || $row->status === AnalysisStatus::Failed) {
            return;
        }

        if ($this->fillRuleBased($row, AnalysisOrigin::Capped, $generationToken ?? $row->generation_token)) {
            $this->ceilingLedger->recordDegradedFill(
                $row->analysis_type->value,
                AnalysisSubjectMap::ownerId($row->subject_type, $row->subject_id),
            );
        }
    }

    private function fillRuleBased(
        Analysis $row,
        ?AnalysisOrigin $reason = null,
        ?string $generationToken = null,
    ): bool {
        $markedDone = false;
        $generationToken ??= $row->generation_token;

        $this->withoutDispatching(function () use ($row, $reason, $generationToken, &$markedDone): void {
            $markedDone = $this->markDone(
                $row,
                app(RuleBasedNarrationFiller::class)->fillFor($row),
                ServedBy::RuleBased,
                ruleBasedReason: $reason,
                generationToken: $generationToken,
            );
        });

        return $markedDone;
    }
}
