<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;
use App\Enums\FeedbackSubject;
use App\Events\EarlyPassStraddled;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\Feedback;
use App\Models\RunCard;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;

class AnalysisRowState
{
    public function __construct(
        private readonly NarrationGate $gate,
        private readonly NotificationEligibility $eligibility,
        private readonly MaintainerAlerter $alerter,
        private readonly CostCeilingLedger $ceilingLedger,
        private readonly NarrationOrigin $origin,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    /**
     * Serve a trigger from the deterministic rule-based filler instead of the LLM. The row is
     * reused (or staged) and marked Done under {@see NarrationGate::withoutDispatching()}, so no
     * job is queued, no cooldown starts and no notification fans out. The demo account's "Reread"
     * resolves here so it can never bill Azure.
     *
     * `$refillDone` false keeps an already-Done row, which may hold real billed narration, from
     * being clobbered with filler prose.
     *
     * `$reason` records on the row why the filler answered ({@see Analysis::$rule_based_reason});
     * it is never left to whatever {@see NarrationOrigin} is ambient.
     */
    public function serveRuleBased(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
        bool $refillDone = true,
        ?AnalysisOrigin $reason = null,
    ): Analysis {
        $row = $this->stagePending($subjectType, $subjectId, $type, $discriminator);

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
    public function stagePending(
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
    ): Analysis {
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

            // Only a user-started narration opens the Reread cooldown. Skipped under
            // withoutDispatching (demo seed) so a freshly seeded demo stays instantly
            // re-narratable on demand.
            // afterCommit: AnalyzeGroupJob wraps several markDone() calls in one
            // DB::transaction(), and the Redis-backed cooldown isn't rolled back by a
            // transaction abort, so starting it eagerly could cool a row whose Done
            // status never committed.
            if (! $this->gate->dispatchSuppressed() && $this->origin->current() === AnalysisOrigin::User) {
                DB::afterCommit(fn () => $row->startCooldown());
            }

            if ($straddled) {
                // The straddling row's own content may have read a history that
                // finished landing mid-generation; ask for it once more now,
                // rather than trust the replay that already ran without it.
                if (! $this->gate->dispatchSuppressed()) {
                    DB::afterCommit(fn () => EarlyPassStraddled::dispatch($row));
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
            AnalysisType::BriefingMascotVoice => $this->backlog->awaitsOlderHydration($row->subject_id, Carbon::now()),
            AnalysisType::ProfileVoice => $this->backlog->awaitsFullHydration($row->subject_id),
            default => false,
        };
    }

    private function earlyPassForActivity(?Activity $activity): bool
    {
        $startedAt = $activity?->detail?->start_date_local;

        return $activity instanceof Activity && $startedAt !== null
            && $this->backlog->awaitsOlderHydration($activity->user_id, $startedAt);
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

    public function upsertRow(
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
     * Only an invalidation the athlete asked for ("Reread", a plan edit, a replan) also re-arms the
     * self-heal budget: system invalidations repeat, so resetting there would make
     * MAX_SELF_HEAL_ATTEMPTS a bound per invalidation rather than per row.
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
    public function claimForDispatch(Analysis $row, bool $invalidate): bool
    {
        return DB::transaction(function () use ($row, $invalidate): bool {
            if ($invalidate) {
                $this->invalidateDoneRow($row);
            }

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
        });
    }

    /**
     * @param  array<int, AnalysisType>  $groupTypes
     * @param  Collection<string, Analysis>  $rows
     */
    public function claimGroupForDispatch(
        string $subjectType,
        int $subjectId,
        ?string $discriminator,
        array $groupTypes,
        Collection $rows,
        bool $invalidate,
    ): ?string {
        return DB::transaction(function () use ($subjectType, $subjectId, $discriminator, $groupTypes, $rows, $invalidate): ?string {
            $typeValues = array_map(fn (AnalysisType $type): string => $type->value, $groupTypes);
            $groupRows = Analysis::query()
                ->where('subject_type', $subjectType)
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
                    ->where('subject_type', $subjectType)
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

    /**
     * Serve a row from the deterministic filler because the spend ceiling is hit, under
     * {@see NarrationGate::withoutDispatching()} so no job is queued, no cooldown starts and no
     * notification claims a narration that was never written.
     *
     * An already-Done row keeps the real prose it was billed for, and a Failed row stays Failed so
     * a genuine fault is not hidden behind plausible content.
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

        $this->gate->withoutDispatching(function () use ($row, $reason, $generationToken, &$markedDone): void {
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
