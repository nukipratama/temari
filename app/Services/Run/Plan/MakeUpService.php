<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\PlannedSession;
use App\Models\User;
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
        'eased_pace_sec_per_km' => null,
        'readiness_assessment' => null,
    ];

    public function __construct(
        private ComplianceScorer $scorer,
        private PlanReconciliationService $reconciliation,
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

    public function notify(User $user, Carbon $vacatedDate, Carbon $targetDate): void
    {
        $this->reconciliation->markDirty($user->id, $vacatedDate->min($targetDate));
    }
}
