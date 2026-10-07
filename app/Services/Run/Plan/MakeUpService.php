<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\Activity;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\PlanNarrationRequester;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Settles a make-up move ({@see SessionEditRules::isMakeUp()}) once
 * {@see \App\Http\Controllers\PlanController::update()} has swapped the two
 * days: {@see self::apply()} inside its lock and transaction,
 * {@see self::notify()} once both are released. See
 * `docs/decisions/a-make-up-is-graded-against-the-moved-session.md`.
 */
final readonly class MakeUpService
{
    private const array CLAMP_RESET = [
        'clamped_km' => null,
        'rest_clamped_at' => null,
        'eased_pace_sec_per_km' => null,
        'readiness_assessment' => null,
    ];

    public function __construct(
        private ComplianceScorer $scorer,
        private PlanReconciliationService $reconciliation,
        private PlanNarrationRequester $planNarration,
        private AnalysisService $analysisService,
    ) {
    }

    public function apply(User $user, PlannedSession $vacated, PlannedSession $target, Carbon $today): void
    {
        $vacated->update([...self::CLAMP_RESET, 'made_up_on' => $target->date]);
        $target->update([...self::CLAMP_RESET, 'skipped' => false, 'made_up_from_id' => $vacated->id]);

        $rows = PlannedSession::query()->whereKey([$vacated->id, $target->id])->orderBy('date')->get();
        $verdicts = $this->scorer->verdictsFor($user, $rows, $today);
        foreach ($rows as $row) {
            $verdict = $verdicts[$row->date->toDateString()] ?? null;
            if ($verdict !== null) {
                ComplianceScorer::applyVerdict($row, $verdict);
            }
        }
    }

    public function notify(User $user, Carbon $vacatedDate, Carbon $targetDate, Carbon $today): void
    {
        $this->reconciliation->markDirty($user->id, $vacatedDate->min($targetDate));
        $this->planNarration->requestDayVoiceIfChanged($user, $vacatedDate);
        $this->planNarration->requestDayVoiceIfChanged($user, $targetDate);

        if ($user->is_demo) {
            return;
        }

        foreach ([...$this->runsOn($user, $targetDate), ...$this->runsOn($user, $vacatedDate)] as $activity) {
            $this->analysisService->requestActivityGroup($activity, invalidate: true);
            if ($activity->runCard !== null) {
                $this->analysisService->request(RunCard::class, $activity->runCard->id, AnalysisType::CardFlavor, invalidate: true);
            }
        }

        if ($targetDate->isSameDay($today) || $vacatedDate->isSameDay($today)) {
            $this->analysisService->requestBriefing($user, $today->toDateString(), invalidate: true);
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
