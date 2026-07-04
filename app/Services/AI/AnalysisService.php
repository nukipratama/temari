<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeRowJob;
use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\RuleBased\RuleBasedInsightBuilder;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\Telegram\NotifiableAnalysis;
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

    public function __construct(
        private readonly RuleBasedNarrationFiller $filler,
        private readonly RuleBasedInsightBuilder $insightBuilder,
        private readonly AppConfig $config,
        private readonly LlmCostCalculator $costCalculator,
        private readonly NotifiableAnalysis $notifiableAnalysis,
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
     * Upsert the Analysis row as Pending without dispatching, filling, or
     * invalidating. For windowed cadences (weekly/monthly) the LLM generation
     * is deferred to the scheduled command that fires once the window closes,
     * instead of re-billing the narration on every ingest inside the window.
     * The row stays visible to the UI (empty state + manual "Baca ulang").
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

    public function requestBriefingGroup(User $user, string $discriminator, bool $invalidate = false, ?int $delaySeconds = null): void
    {
        $this->dispatchGroup(AnalyzeBriefingJob::class, $user->id, $discriminator, $invalidate, $delaySeconds);
    }

    public function markProcessing(Analysis $row): void
    {
        $row->update([
            'status' => AnalysisStatus::Processing,
            'attempts' => $row->attempts + 1,
        ]);
    }

    public function markDone(Analysis $row, string $content, ?Carbon $generatedAt = null): void
    {
        $row->update([
            'status' => AnalysisStatus::Done,
            'content' => $content,
            'error' => null,
            'generated_at' => $generatedAt ?? Carbon::now(),
        ]);

        // Start the re-trigger cooldown so a "Baca ulang" can't re-fire the LLM
        // for the same block within the window (covers both auto and manual).
        // Skipped under withoutDispatching (demo seed) so a freshly seeded demo
        // stays instantly re-narratable on demand. afterCommit: AnalyzeGroupJob
        // wraps several markDone() calls in one DB::transaction(), and the
        // Redis-backed cooldown isn't rolled back by a transaction abort, so
        // starting it eagerly could cool a row whose Done status never committed.
        if (! $this->dispatchSuppressed) {
            DB::afterCommit(fn () => $row->startCooldown());
        }

        // Fan out a Telegram push for the notifiable types. Suppressed under
        // withoutDispatching (demo seed) and a no-op when Telegram is unconfigured;
        // the job itself enforces the demo / opt-in / connection / idempotency
        // guards. afterCommit so it can't run before the row it reads is committed.
        if (! $this->dispatchSuppressed
            && filled(config('services.telegram.bot_token'))
            && $this->notifiableAnalysis->isNotifiable($row)) {
            SendTelegramNotificationJob::dispatch($row->id)->afterCommit();
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

        // Rule-based types carry deterministic content (no LLM) -> fill inline.
        if ($type->isRuleBased()) {
            if ($justCreated || ($invalidate && $row->status === AnalysisStatus::Done)) {
                $this->markDone($row, $this->ruleBasedContent($row));
                $row->refresh();
            }

            return $row;
        }

        // Generation paused (cost ceiling / AI off / Azure unset / demo seed):
        // stay honest -> a fresh row rests Pending for the empty state, an existing
        // Done keeps its real prose. Never substitute a template; ai:self-heal
        // resumes it once generation is back (demo flat-fills via its own seeder).
        if (! $this->autoDispatchEnabled()) {
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
        $this->dispatchPending($jobClass::dispatch($row->id), $delaySeconds);

        return $row;
    }

    /** Generate deterministic content for rule-based analysis types. */
    private function ruleBasedContent(Analysis $row): string
    {
        if ($row->analysis_type === AnalysisType::TrendCaption) {
            return $this->insightBuilder->trendCaption(
                User::query()->findOrFail($row->subject_id),
                $row->discriminator !== null ? Carbon::parse($row->discriminator) : Carbon::today(),
            );
        }

        $activity = Activity::query()->with('detail')->findOrFail($row->subject_id);
        $detail = $activity->detail;

        if ($detail === null) {
            return $this->filler->fillFor($row);
        }

        return match ($row->analysis_type) {
            AnalysisType::RunInsightTechnical => $this->insightBuilder->runInsightTechnical($activity, $detail),
            AnalysisType::RunInsightSplits => $this->insightBuilder->runInsightSplits($detail),
            AnalysisType::RunInsightZones => $this->insightBuilder->runInsightZones($detail),
            default => $this->filler->fillFor($row),
        };
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

        if (! $anyJustCreated && ! $rows->contains(fn (Analysis $row): bool => $this->rowNeedsDispatch($row))) {
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
            $row = $existing->get($type->value) ?? Analysis::query()->firstOrCreate(
                [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'analysis_type' => $type,
                    'discriminator' => $discriminator,
                ],
                $defaults,
            );
            $rows->put($type->value, $row);
        }

        return $rows;
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
        $pending->onQueue($this->queueName());
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }

        // Defer the actual enqueue until any surrounding DB transaction commits
        // (e.g. ActivityPipeline::ingest wraps the story layer, which dispatches
        // CardFlavor). Without this the job could run before — or be orphaned by
        // a rollback of — the Analysis row it targets. A no-op when not in a txn.
        $pending->afterCommit();
    }

    /**
     * True when LLM generation is paused for everyone right now: daily cost
     * ceiling hit, the AiEnabled kill-switch off, Azure unconfigured, or a
     * demo-seed suppression. ai:self-heal early-exits on it and the analyze jobs
     * refuse to bill on it; a paused row rests Pending until generation resumes.
     */
    public function generationPaused(): bool
    {
        return ! $this->autoDispatchEnabled();
    }

    /**
     * Why generation is paused right now, for the /pulse dashboard's status
     * line — null when healthy. Checked in the same precedence as
     * {@see self::autoDispatchEnabled()}, but reported as a reason instead of
     * a single boolean so "kill switch off" reads differently from "cost
     * ceiling hit today".
     */
    public function pauseReason(): ?string
    {
        if (! $this->config->boolean(AppConfigKey::AiEnabled)) {
            return 'kill_switch';
        }

        if (blank(config('azure_openai.uri')) || blank(config('azure_openai.api_key'))) {
            return 'unconfigured';
        }

        if ($this->dailyCostCeilingExceeded()) {
            return 'cost_ceiling';
        }

        return null;
    }

    private function autoDispatchEnabled(): bool
    {
        return ! $this->dispatchSuppressed
            && $this->config->boolean(AppConfigKey::AiEnabled)
            && (bool) config('ai.auto_dispatch', true)
            && filled(config('azure_openai.uri'))
            && filled(config('azure_openai.api_key'))
            && ! $this->dailyCostCeilingExceeded();
    }

    /**
     * True when a daily_cost_ceiling is configured and today's LLM spend has
     * already exceeded it, so further auto-dispatch is skipped to cap cost. No
     * ceiling configured (null) means this never gates dispatch.
     */
    private function dailyCostCeilingExceeded(): bool
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

        return true;
    }

    private function queueName(): string
    {
        return (string) config('ai.queue', 'default');
    }
}
