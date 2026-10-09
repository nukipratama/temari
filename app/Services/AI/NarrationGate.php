<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Ops\MaintainerAlerter;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Support\Facades\Log;

/**
 * Whether narration may be billed to the LLM right now: the pause reasons, the
 * per-athlete and app-wide cost ceilings, and the open-recap and demo checks
 * that decide how a trigger is served.
 */
class NarrationGate
{
    private bool $dispatchSuppressed = false;

    /**
     * Memoized {@see self::dailyCostCeilingExceeded()} answers, keyed by the
     * athlete the question was asked about (`'total'` for the app-wide one). The
     * service is a `scoped` binding, so this lives exactly one HTTP request or
     * one queue job: both the queue worker and Octane discard it via
     * forgetScopedInstances(). Only the cost read is memoized -- the kill switch
     * and the config breaker stay live, so a breaker reset still resumes
     * generation within the scope.
     *
     * @var array<string, bool>
     */
    private array $costCeilingMemo = [];

    public function __construct(
        private readonly AppConfig $config,
        private readonly LlmCostCalculator $costCalculator,
        private readonly AzureConfigCircuitBreaker $configBreaker,
        private readonly MaintainerAlerter $alerter,
        private readonly CostCeilingLedger $ceilingLedger,
        private readonly NarrationOrigin $origin,
        private readonly CeilingOverride $ceilingOverride,
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

    public function dispatchSuppressed(): bool
    {
        return $this->dispatchSuppressed;
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
     * ({@see AnalysisService::requestRuleBased()}) rather than the LLM. The demo login is
     * public, so callers must ask this ahead of the pause, chain-resume and
     * zone-recompute paths, which only exist to shape a billed narration.
     */
    public function shouldServeRuleBased(User $user): bool
    {
        return $user->is_demo;
    }

    /**
     * True when nothing may be billed to the LLM for anyone right now: daily
     * cost ceiling hit, the AiEnabled kill-switch off, Azure unconfigured, or a
     * demo-seed suppression. ai:self-heal early-exits on it, manual triggers are
     * refused on it, and the analyze jobs refuse to bill on it. Rows rest Pending
     * until generation resumes, except under the ceiling, which serves them from
     * the filler instead ({@see self::costCeilingDegraded()}).
     */
    public function generationPaused(?int $userId = null): bool
    {
        return ! $this->autoDispatchEnabled($userId);
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

    public function autoDispatchEnabled(?int $userId = null): bool
    {
        return $this->blockingReason(withBudget: true, probeBreaker: true, userId: $userId) === null;
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
    private function blockingReason(bool $withBudget, bool $probeBreaker, ?int $userId = null): ?string
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

        if ($withBudget && $this->dailyCostCeilingExceeded($userId)) {
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
    public function costCeilingDegraded(?int $userId = null): bool
    {
        return $this->dispatchAllowedIgnoringBudget() && $this->dailyCostCeilingExceeded($userId);
    }

    /**
     * True when spend has already passed a ceiling that gates this caller: the
     * app-wide total, which gates everyone including a caller with no athlete in
     * hand (the /pulse status line, ai:self-heal), or this athlete's own slice
     * underneath it. A ceiling left null never gates.
     *
     * A replay swaps the athlete's slice for its own app-wide cap
     * ({@see self::replayCapExceeded()}); the total still gates it.
     */
    private function dailyCostCeilingExceeded(?int $userId = null): bool
    {
        if ($this->ceilingExceeded('total', self::configCeiling('azure_openai.daily_cost_ceiling_total'), null, appWide: true)) {
            return true;
        }

        if ($this->origin->current() === AnalysisOrigin::Replay) {
            return $this->replayCapExceeded();
        }

        // Memoized per athlete, prefixed so the key stays a string: PHP silently
        // casts a numeric string array key to an int, which the declared shape
        // is not.
        return $userId !== null
            && $this->ceilingExceeded('user:'.$userId, $this->perUserCeiling($userId), $userId);
    }

    /**
     * This athlete's ceiling for today: an operator's today-only override when
     * one is set, otherwise the configured slice.
     */
    private function perUserCeiling(int $userId): ?float
    {
        return $this->ceilingOverride->get($userId)
            ?? self::configCeiling('azure_openai.daily_cost_ceiling_per_user');
    }

    /**
     * Whether today's replay spend has reached its own cap. A replay is an
     * operator's QA tool rather than an athlete's narration, so it is measured
     * against this cap and the app-wide total, and deliberately never against
     * the athlete's own slice — replaying their block must not cost them the
     * budget their real narration needs. Refused *at* the cap rather than past
     * it, unlike the two ceilings, since a replay is discretionary.
     */
    private function replayCapExceeded(): bool
    {
        $cap = self::configCeiling('azure_openai.replay_daily_cap');
        if ($cap === null) {
            return false;
        }

        return $this->costCalculator->dailyCost(origin: AnalysisOrigin::Replay) >= $cap;
    }

    private static function configCeiling(string $configKey): ?float
    {
        $ceiling = config($configKey);

        return is_numeric($ceiling) ? (float) $ceiling : null;
    }

    /**
     * A ceiling with today's spend already past it, memoized under `$memoKey`
     * for the life of the scope. Null ceiling means that ceiling never gates
     * dispatch.
     *
     * Both ceilings share this path so the per-athlete slice and the app-wide
     * total can never diverge in what "past the ceiling" does. `$appWide` marks
     * the total, whose trip is an incident worth a maintainer push rather than
     * one athlete's ordinary day.
     */
    private function ceilingExceeded(string $memoKey, ?float $ceiling, ?int $userId, bool $appWide = false): bool
    {
        if (isset($this->costCeilingMemo[$memoKey])) {
            return $this->costCeilingMemo[$memoKey];
        }

        if ($ceiling === null) {
            return $this->costCeilingMemo[$memoKey] = false;
        }

        $todayCost = $this->costCalculator->dailyCost($userId);
        if ($todayCost <= $ceiling) {
            if ($appWide) {
                $this->alerter->totalCeilingApproaching($todayCost, $ceiling);
            }

            return $this->costCeilingMemo[$memoKey] = false;
        }

        Log::warning('ai.daily_cost_ceiling_exceeded', [
            'today_cost' => $todayCost,
            'ceiling' => $ceiling,
            'user_id' => $userId,
        ]);
        $this->ceilingLedger->recordTrip();

        if ($appWide) {
            $this->alerter->totalCeilingReached($todayCost, $ceiling, User::query()->notDemo()->count());
        } elseif ($userId !== null) {
            $this->alerter->userCeilingReached($userId, $todayCost, $ceiling);
        }

        return $this->costCeilingMemo[$memoKey] = true;
    }
}
