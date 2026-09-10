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
 * Both entry points reuse {@see AnalysisService::requestBriefing()}, which is
 * the upsert the kickoff and the hourly catch-up already share: an existing row
 * is never duplicated, and the demo account is served from the deterministic
 * filler like every other trigger it can reach.
 */
class RequestTodaysBriefing
{
    public function __construct(private readonly AnalysisService $service)
    {
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
     * connect chain must not re-bill the day's briefing on every pass.
     */
    public function afterBackfill(User $user): void
    {
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

        $this->service->requestBriefing($user, $today, $invalidate);
    }
}
