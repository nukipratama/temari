<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * How well the athlete held the plan over a span, as counts rather than days.
 *
 * The aggregate counterpart to {@see PlanContextTool}, which returns one entry
 * per prescribed day. A day list is the right answer for a week or a month and
 * the wrong one for a range measured in quarters: it grows with the span, and a
 * narrator reading a year is asking about a shape, not a register.
 *
 * A null `$from` means the athlete's whole history, which is what a persona
 * summary is about.
 */
final class PlanAdherenceTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly ?Carbon $from,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_plan_adherence';
    }

    public function description(): string
    {
        return 'How the athlete held their training plan over the period this block covers, as '
            .'counts rather than a list of days: how many sessions the plan prescribed, and how '
            .'many came back done, partial, missed or overreached (ran well past what was asked). '
            .'excused counts days they cancelled in advance, which never count against them; '
            .'ran_anyway counts days they excused and then ran regardless. mean_compliance is the '
            .'average score out of 100 across the days that were graded, or null when none were. '
            .'prescribed 0 means no plan covered this period.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $sessions = PlannedSession::query()
            ->where('user_id', $this->user->id)
            ->where('date', '<=', $this->asOf->toDateString())
            ->when($this->from !== null, fn ($query) => $query->where('date', '>=', $this->from?->toDateString()))
            ->get();

        $meanCompliance = $sessions->whereNotNull('compliance_score')->avg('compliance_score');
        $byStatus = $sessions->countBy(fn (PlannedSession $session): string => $session->status->value);

        return [
            'from' => $this->from?->toDateString(),
            'through' => $this->asOf->toDateString(),
            'prescribed' => $sessions->count(),
            'done' => $byStatus->get(PlannedSessionStatus::Done->value, 0),
            'partial' => $byStatus->get(PlannedSessionStatus::Partial->value, 0),
            'missed' => $byStatus->get(PlannedSessionStatus::Missed->value, 0),
            'overreached' => $byStatus->get(PlannedSessionStatus::Overreached->value, 0),
            'excused' => $byStatus->get(PlannedSessionStatus::Skip->value, 0),
            'ran_anyway' => $sessions->where('ran_anyway', true)->count(),
            'mean_compliance' => $meanCompliance === null ? null : (int) round($meanCompliance),
        ];
    }
}
