<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\HistoryNarrationGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Requests one athlete's `BriefingMascotVoice` row for today outside the 00:01
 * kickoff, so a brand-new account has a briefing on the day it signs up rather
 * than after its first midnight.
 *
 * Both entry points reuse {@see AnalysisService::requestBriefing()}, which is
 * the upsert the kickoff and the hourly catch-up already share: an existing row
 * is never duplicated, and the demo account is served from the deterministic
 * filler like every other trigger it can reach.
 *
 * Both are held (staged Pending, not generated) while the athlete's history is
 * still hydrating, the same reach {@see \App\Listeners\DispatchPostRunAnalysis}
 * already bounds the ingest-time briefing request on
 * ({@see HistoryNarrationGate::awaitsOlderHydration()}).
 * {@see \App\Services\AI\SelfHealer} releases the held row once that clears.
 */
class RequestTodaysBriefing
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly HistoryNarrationGate $history,
    ) {
    }

    public function atSignup(User $user): void
    {
        $this->request($user, invalidate: false);
    }

    /**
     * The same row again once the Strava backfill lands, invalidating so the
     * briefing narrated against an empty history is replaced by one that read
     * the real thing. `BriefingMascotVoice` stamps no material fingerprint, so
     * a plain request would leave a Done row alone.
     *
     * Bounded to one re-request per athlete per day: a resync that re-runs the
     * connect chain must not re-bill the day's briefing on every pass. Checked
     * only once the hold has cleared, so a re-run while history is still
     * hydrating can never consume the day's one real request — it just
     * re-stages the same Pending row, which is idempotent.
     */
    public function afterBackfill(User $user): void
    {
        if ($this->awaitsHydration($user)) {
            $this->stageDeferred($user);

            return;
        }

        $key = "briefing-after-backfill:{$user->id}:".Carbon::today()->toDateString();

        if (! Cache::add($key, true, Carbon::tomorrow())) {
            return;
        }

        $this->request($user, invalidate: true);
    }

    private function request(User $user, bool $invalidate): void
    {
        $today = Carbon::today()->toDateString();

        if ($this->service->shouldServeRuleBased($user)) {
            $this->service->requestRuleBased(
                AnalysisType::BRIEFING_SUBJECT_TYPE,
                $user->id,
                AnalysisType::BriefingMascotVoice,
                $today,
                refillDone: $invalidate,
            );

            return;
        }

        if ($this->awaitsHydration($user)) {
            $this->stageDeferred($user);

            return;
        }

        $this->service->requestBriefing($user, $today, $invalidate);
    }

    /**
     * A brand-new connection has no Activity rows yet at `atSignup()` time —
     * the backfill sync hasn't run — so {@see HistoryNarrationGate::awaitsOlderHydration()}
     * would vacuously read "nothing awaiting hydration" (there is nothing to
     * find) and let the briefing through against zero history. `backfilled_at`
     * (stamped by {@see \App\Jobs\AI\KickoffRecapsJob} right before it calls
     * `afterBackfill()`) is null for exactly that window, so checking it first
     * covers what the row-based gate can't see yet; once it's set, the same
     * bounded gate takes over for the remaining (detail-hydration) gap.
     */
    private function awaitsHydration(User $user): bool
    {
        return $user->backfilled_at === null || $this->history->awaitsOlderHydration($user->id, Carbon::now());
    }

    private function stageDeferred(User $user): void
    {
        $this->service->requestDeferred(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            Carbon::today()->toDateString(),
        );
    }
}
