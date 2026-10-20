<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Requests one athlete's `BriefingMascotVoice` row for today outside the 00:01
 * kickoff, so a brand-new account has a briefing on the day it signs up rather
 * than after its first midnight.
 *
 * Both entry points reuse {@see AnalysisService::requestBriefing()}, the upsert
 * the kickoff and the hourly catch-up already share, so an existing row is never
 * duplicated.
 *
 * Both are held (staged Pending, not generated) only while `backfilled_at` is
 * still null; see docs/decisions/history-narrates-on-demand.md.
 */
class RequestTodaysBriefing
{
    public function __construct(
        private readonly AnalysisService $service,
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
     * Bounded to one re-request per athlete per day, checked only once the hold
     * has cleared.
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
     * the backfill sync hasn't run — so there is no history at all to narrate
     * against. `backfilled_at` (stamped by {@see \App\Jobs\AI\KickoffRecapsJob}
     * right before it calls `afterBackfill()`) is null for exactly that
     * window; once it's set, the briefing narrates right away, early-pass or
     * not.
     */
    private function awaitsHydration(User $user): bool
    {
        return $user->backfilled_at === null;
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
