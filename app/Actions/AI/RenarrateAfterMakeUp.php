<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\Activity;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Re-narrates the runs on both days of a make-up move and, when either day is
 * today, today's briefing, each delayed by
 * {@see AnalysisService::PLAN_EDIT_DELAY_SECONDS} so a burst of moves merges
 * into one job per row. The demo athlete is never narrated.
 */
final readonly class RenarrateAfterMakeUp
{
    public function __construct(private AnalysisService $analysisService)
    {
    }

    public function __invoke(User $user, Carbon $vacatedDate, Carbon $targetDate, Carbon $today): void
    {
        if ($user->is_demo) {
            return;
        }

        foreach ([...$this->runsOn($user, $targetDate), ...$this->runsOn($user, $vacatedDate)] as $activity) {
            $this->analysisService->requestActivityGroup($activity, invalidate: true, delaySeconds: AnalysisService::PLAN_EDIT_DELAY_SECONDS);
            if ($activity->runCard !== null) {
                $this->analysisService->request(RunCard::class, $activity->runCard->id, AnalysisType::CardFlavor, delaySeconds: AnalysisService::PLAN_EDIT_DELAY_SECONDS, invalidate: true);
            }
        }

        if ($targetDate->isSameDay($today) || $vacatedDate->isSameDay($today)) {
            $this->analysisService->requestBriefing($user, $today->toDateString(), invalidate: true, delaySeconds: AnalysisService::PLAN_EDIT_DELAY_SECONDS);
        }
    }

    /** @return Collection<int, Activity> */
    private function runsOn(User $user, Carbon $date): Collection
    {
        return Activity::analyzedJoinConstraint(Activity::query())
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($detail) => $detail->whereDate('start_date_local', $date->toDateString()))
            ->with('runCard')
            ->get();
    }
}
