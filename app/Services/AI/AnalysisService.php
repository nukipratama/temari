<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\Telegram\NotificationEligibility;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

class AnalysisService
{
    private bool $dispatchSuppressed = false;

    /**
     * Memoized {@see self::dailyCostCeilingExceeded()} answer. The service is a
     * `scoped` binding, so this lives exactly one HTTP request or one queue job:
     * both the queue worker and Octane discard it via forgetScopedInstances().
     * Only the cost read is memoized -- the kill switch and the config breaker
     * stay live, so a breaker reset still resumes generation within the scope.
     */
    private ?bool $costCeilingMemo = null;

    public function __construct(
        private readonly AppConfig $config,
        private readonly LlmCostCalculator $costCalculator,
        private readonly NotificationEligibility $eligibility,
        private readonly AzureConfigCircuitBreaker $configBreaker,
        private readonly MaintainerAlerter $alerter,
        private readonly ChainResolver $chains,
        private readonly CostCeilingLedger $ceilingLedger,
        private readonly NarrationOrigin $origin,
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
     */
    public function requestRuleBased(
        Model|string $subjectOrType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
        bool $refillDone = true,
    ): Analysis {
        $row = $this->requestDeferred($subjectOrType, $subjectId, $type, $discriminator);

        if (! $refillDone && $row->status === AnalysisStatus::Done) {
            return $row;
        }

        $this->fillRuleBased($row);

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
    public function requestActivityGroupRuleBased(Activity $activity): void
    {
        foreach (AnalyzeActivityJob::groupedTypes() as $type) {
            $this->requestRuleBased(AnalyzeActivityJob::subjectType(), $activity->id, $type, refillDone: false);
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

    public function requestBriefing(User $user, string $discriminator, bool $invalidate = false, ?int $delaySeconds = null): void
    {
        $this->dispatchRow(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            $discriminator,
            $invalidate,
            $delaySeconds,
        );
    }

    public function markProcessing(Analysis $row): void
    {
        $row->update([
            'status' => AnalysisStatus::Processing,
            'attempts' => $row->attempts + 1,
        ]);
    }

    public function markDone(Analysis $row, string $content, ?Carbon $generatedAt = null, ?string $fingerprint = null): void
    {
        $row->update([
            'status' => AnalysisStatus::Done,
            'content' => $content,
            'error' => null,
            'generated_at' => $generatedAt ?? Carbon::now(),
            // Only per-run activity groups pass a fingerprint; write the existing
            // value back for other narration types (they don't drive a resync
            // refresh) so the column is simply untouched.
            'content_fingerprint' => $fingerprint ?? $row->content_fingerprint,
        ]);

        // Start the re-trigger cooldown so a "Reread" can't re-fire the LLM
        // for the same block within the window (covers both auto and manual).
        // Skipped under withoutDispatching (demo seed) so a freshly seeded demo
        // stays instantly re-narratable on demand. afterCommit: AnalyzeGroupJob
        // wraps several markDone() calls in one DB::transaction(), and the
        // Redis-backed cooldown isn't rolled back by a transaction abort, so
        // starting it eagerly could cool a row whose Done status never committed.
        if (! $this->dispatchSuppressed) {
            DB::afterCommit(fn () => $row->startCooldown());
        }

        // Fan out a notification for the notifiable types. Suppressed under
        // withoutDispatching (demo seed); the notification's via() owns every guard
        // (demo / recency / opt-in / channel wired) and the channel owns idempotency,
        // so a demo or opted-out user resolves to no channels at all while an
        // unwired one still gets the inbox record. afterCommit so the queued send
        // can't run before the row it reads is committed.
        if (! $this->dispatchSuppressed && $this->eligibility->isNotifiable($row)) {
            $this->eligibility->resolveUser($row)?->notify(
                new AnalysisReadyNotification($row)->afterCommit(),
            );
        }
    }

    public function markFailed(Analysis $row, string $error): void
    {
        $row->update([
            'status' => AnalysisStatus::Failed,
            'error' => $error,
        ]);

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
        $row = $this->upsertRow($subjectType, $subjectId, $type, $discriminator);
        $justCreated = $row->wasRecentlyCreated;

        // Generation paused (AI off / Azure unset / broken / demo seed): stay
        // honest -> a fresh row rests Pending for the empty state, an existing
        // Done keeps its real prose, and ai:self-heal resumes it once generation
        // is back. The spend ceiling is the one pause that degrades instead.
        if (! $this->autoDispatchEnabled()) {
            if ($this->costCeilingDegraded()) {
                $this->degradeToRuleBased($row);
            }

            return $row;
        }

        if (! $justCreated) {
            if ($invalidate && $row->status === AnalysisStatus::Done) {
                $row->update(['status' => AnalysisStatus::Pending, 'error' => null, 'attempts' => 0]);
                $row->refresh();
            }

            if (! $this->rowNeedsDispatch($row)) {
                return $row;
            }

            $this->markQueued($row);
        }

        /** @var class-string<AnalyzeRowJob> $jobClass */
        $jobClass = $type->jobClass();
        $this->dispatchPending($this->stamped(new $jobClass($row->id)), $delaySeconds);

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
            if ($this->costCeilingDegraded()) {
                foreach ($rows as $row) {
                    $this->degradeToRuleBased($row);
                }
            }

            return;
        }

        if ($invalidate) {
            $this->invalidateDoneRows($rows);
        }

        if (! $anyJustCreated && ! $rows->contains(fn (Analysis $row): bool => $this->rowNeedsDispatch($row))) {
            return;
        }

        foreach ($rows as $row) {
            if (! $row->wasRecentlyCreated && $this->rowNeedsDispatch($row)) {
                $this->markQueued($row);
            }
        }

        $this->dispatchPending($this->stamped(new $jobClass($subjectId, $discriminator)), $delaySeconds);
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
     * Bulk-fetch all group rows in one SELECT and insert any missing ones in one
     * INSERT IGNORE + one re-SELECT. Returns a Collection keyed by the
     * AnalysisType value (so callers can look up by type without rescanning) in
     * the order of $groupTypes; rows this call brought into existence carry
     * `wasRecentlyCreated`, which drives the dispatch decision in dispatchGroup().
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
     * values, and the re-read rows are flagged `wasRecentlyCreated` by hand
     * because a SELECT would otherwise report them as pre-existing.
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
        $canDispatch = $this->autoDispatchEnabled();
        $now = Carbon::now();

        Analysis::query()->insertOrIgnore(array_map(fn (string $value): array => [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'analysis_type' => $value,
            'discriminator' => $discriminator,
            'status' => ($canDispatch ? AnalysisStatus::Queued : AnalysisStatus::Pending)->value,
            'queued_at' => $canDispatch ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $typeValues));

        return $this->fetchGroupRows($subjectType, $subjectId, $discriminator, $typeValues)
            ->each(function (Analysis $row): void {
                $row->wasRecentlyCreated = true;
            });
    }

    /** @param Collection<array-key, Analysis> $rows */
    private function invalidateDoneRows(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($row->status === AnalysisStatus::Done) {
                $row->update(['status' => AnalysisStatus::Pending, 'error' => null, 'attempts' => 0]);
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

    public function markQueued(Analysis $row): void
    {
        $row->update([
            'status' => AnalysisStatus::Queued,
            'queued_at' => Carbon::now(),
            'error' => null,
        ]);
    }

    /**
     * Send a row back to Pending without touching `attempts`, used by the
     * analyze jobs when generation is paused mid-flight: the row rests Pending
     * for the empty state and ai:self-heal re-dispatches it later, but its
     * self-heal budget is preserved (this was not a real LLM attempt).
     */
    public function revertToPending(Analysis $row): void
    {
        $row->update([
            'status' => AnalysisStatus::Pending,
            'queued_at' => null,
        ]);
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

    /**
     * Whether the trigger targets the still-running current recap period (this
     * week or this month). Its row is staged Pending but must never be narrated
     * on demand — it would describe an incomplete period — so the caller returns
     * the row unchanged and lets the scheduled command narrate it once the period
     * closes. Only the windowed recap kinds can be open; every other type is
     * always narratable on demand.
     */
    public function isStillOpenRecapPeriod(AnalysisType $type, int $subjectId, ?string $discriminator): bool
    {
        return match ($type) {
            AnalysisType::MonthlyRecap => $discriminator !== null
                && $discriminator > RecapPeriod::lastClosedMonth(),
            AnalysisType::WeeklyRecap => WeeklySnapshot::query()
                ->whereKey($subjectId)
                ->where('week_ending', '>', RecapPeriod::lastClosedWeekEnding())
                ->exists(),
            default => false,
        };
    }

    /**
     * Whether this user's trigger must be served from the deterministic filler
     * ({@see self::requestRuleBased()}) rather than the LLM. The demo login is
     * public, so callers must ask this ahead of the pause, chain-resume and
     * zone-recompute paths, which only exist to shape a billed narration.
     */
    public function shouldServeRuleBased(User $user): bool
    {
        return $user->is_demo;
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

    /**
     * True when nothing may be billed to the LLM for anyone right now: daily
     * cost ceiling hit, the AiEnabled kill-switch off, Azure unconfigured, or a
     * demo-seed suppression. ai:self-heal early-exits on it, manual triggers are
     * refused on it, and the analyze jobs refuse to bill on it. Rows rest Pending
     * until generation resumes, except under the ceiling, which serves them from
     * the filler instead ({@see self::costCeilingDegraded()}).
     */
    public function generationPaused(): bool
    {
        return ! $this->autoDispatchEnabled();
    }

    /**
     * Why generation is paused right now, for the /pulse dashboard's status
     * line — null when healthy. The same list {@see self::autoDispatchEnabled()}
     * decides on, reported as a reason instead of a single boolean so "kill
     * switch off" reads differently from "cost ceiling hit today".
     */
    public function pauseReason(): ?string
    {
        return $this->blockingReason(withBudget: true, probeBreaker: false);
    }

    private function autoDispatchEnabled(): bool
    {
        return $this->blockingReason(withBudget: true, probeBreaker: true) === null;
    }

    private function dispatchAllowedIgnoringBudget(): bool
    {
        return $this->blockingReason(withBudget: false, probeBreaker: true) === null;
    }

    /**
     * The first condition stopping an auto-dispatch, or null when none does.
     * `withBudget: false` drops the daily spend ceiling so the caller can tell a
     * budget stop from every other one.
     *
     * The breaker half-opens after a cooldown to allow a single probe, so a
     * caller about to dispatch passes `probeBreaker: true` to take it; a caller
     * only reporting passes false and reads the state without consuming it.
     */
    private function blockingReason(bool $withBudget, bool $probeBreaker): ?string
    {
        if ($this->dispatchSuppressed) {
            return 'suppressed';
        }

        if (! $this->config->boolean(AppConfigKey::AiEnabled)) {
            return 'kill_switch';
        }

        if (! (bool) config('ai.auto_dispatch', true)) {
            return 'auto_dispatch';
        }

        if (blank(config('azure_openai.uri')) || blank(config('azure_openai.api_key'))) {
            return 'unconfigured';
        }

        if ($probeBreaker ? ! $this->configBreaker->allowsRequest() : $this->configBreaker->isTripped()) {
            return 'config';
        }

        if ($withBudget && $this->dailyCostCeilingExceeded()) {
            return 'cost_ceiling';
        }

        return null;
    }

    /**
     * True when the daily spend ceiling is the *only* reason nothing may be
     * billed right now. Every other stop is a fault or an explicit switch, which
     * a Pending row honestly represents and ai:self-heal resumes for free; the
     * budget instead resolves on a clock, so waiting buys nothing and the block
     * is served from the deterministic filler.
     */
    public function costCeilingDegraded(): bool
    {
        return $this->dispatchAllowedIgnoringBudget() && $this->dailyCostCeilingExceeded();
    }

    /**
     * Serve a row from the deterministic filler because the spend ceiling is
     * hit. Filled under {@see self::withoutDispatching()} so no job is queued, no
     * cooldown starts and no notification claims a narration that was never
     * written.
     *
     * Two statuses are left alone. An already-Done row keeps the real prose it
     * was billed for. A Failed row is a genuine fault (content filter, malformed
     * response, spent retry budget) that the bounded self-heal and the /devtools/ai-usage
     * dead-letter exist to surface, so it stays Failed with its "Try again"
     * rather than hiding a break behind plausible content — on a day the ceiling
     * trips repeatedly, filling it would erase that signal every time.
     */
    public function degradeToRuleBased(Analysis $row): void
    {
        if ($row->status === AnalysisStatus::Done || $row->status === AnalysisStatus::Failed) {
            return;
        }

        $this->fillRuleBased($row);
        $this->ceilingLedger->recordDegradedFill();
    }

    private function fillRuleBased(Analysis $row): void
    {
        $this->withoutDispatching(function () use ($row): void {
            $this->markDone($row, app(RuleBasedNarrationFiller::class)->fillFor($row));
        });
    }

    /**
     * True when a daily_cost_ceiling is configured and today's LLM spend has
     * already exceeded it, so further auto-dispatch is skipped to cap cost. No
     * ceiling configured (null) means this never gates dispatch.
     */
    private function dailyCostCeilingExceeded(): bool
    {
        return $this->costCeilingMemo ??= $this->computeDailyCostCeilingExceeded();
    }

    private function computeDailyCostCeilingExceeded(): bool
    {
        $ceiling = config('azure_openai.daily_cost_ceiling');
        if ($ceiling === null) {
            return false;
        }

        $todayCost = $this->costCalculator->dailyCost();
        if ($todayCost <= (float) $ceiling) {
            return false;
        }

        Log::warning('ai.daily_cost_ceiling_exceeded', [
            'today_cost' => $todayCost,
            'ceiling' => (float) $ceiling,
        ]);
        $this->ceilingLedger->recordTrip();

        return true;
    }
}
