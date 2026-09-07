<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\PlanNarrationRequester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * The last link of the first-connect chain: weekly + monthly recap kickoff for
 * a single user, so a new athlete's history narrates on day one instead of
 * waiting for the Monday / 1st-of-month sweep. Reads only rows the backfill
 * already wrote, so it spends no Strava budget, and the kickoff actions skip
 * every Done recap, so a re-run bills nothing.
 *
 * Being last, it is also where `users.backfilled_at` is stamped — the marker
 * nothing else could supply, since the chain's own position is unreadable from
 * outside it — and where the first week is narrated if onboarding already wrote
 * a plan to narrate.
 */
class KickoffRecapsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(
        KickoffWeeklyRecaps $weekly,
        KickoffMonthlyRecaps $monthly,
        PlanNarrationRequester $planNarration,
    ): void {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $weekly($this->userId);
        $monthly($this->userId);

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $user->markBackfilled();

        if (PlannedSession::query()->where('user_id', $user->id)->exists()) {
            $planNarration->requestForFirstWeek($user, Carbon::today());
        }
    }
}
