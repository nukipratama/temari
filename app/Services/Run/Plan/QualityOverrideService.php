<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanOverrideKind;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes and undoes the week's one {@see QualityOverride}. A take is accepted only where the current
 * week, rendered as the athlete sees it, offers one.
 */
final readonly class QualityOverrideService
{
    public function __construct(
        private Periodizer $periodizer,
        private CurrentWeekPlanBuilder $currentWeek,
        private RestClampRecorder $restClampRecorder,
        private SessionMatcher $sessionMatcher,
    ) {
    }

    public function take(User $user, PlannedSession $session, Carbon $today): PlanOverrideKind
    {
        return $this->periodizer->withRegenerationLock($user, fn (): PlanOverrideKind => $this->takeWithinLock($user, $session->fresh() ?? throw self::refusal("this day can't be overridden."), $today), Periodizer::REQUEST_LOCK_WAIT_SECONDS);
    }

    private function takeWithinLock(User $user, PlannedSession $session, Carbon $today): PlanOverrideKind
    {
        $day = collect($this->currentWeek->forUser($user, $today)['days'] ?? [])->firstWhere('id', $session->id);
        $offer = is_array($day) ? $day['override'] : null;
        if (! is_array($offer) || $offer['state'] !== 'offered' || ! is_string($offer['kind'])) {
            throw self::refusal("this day can't be overridden.");
        }
        $kind = PlanOverrideKind::from($offer['kind']);

        DB::transaction(function () use ($user, $session, $today, $kind): void {
            $week = $this->lockedWeek($user, $session);
            if (! $week instanceof PlanAdaptation || $week->override_kind instanceof PlanOverrideKind) {
                throw self::refusal("this day can't be overridden.");
            }
            $week->update(['override_kind' => $kind, 'override_date' => $session->date->toDateString(), 'overridden_at' => Carbon::now('UTC')]);

            if ($kind === PlanOverrideKind::ReadinessEase) {
                $session->update(['clamped_km' => null, 'eased_pace_sec_per_km' => null, 'readiness_assessment' => null]);

                return;
            }

            $this->periodizer->regenerateWithinLock($user, $today);
            $restored = PlannedSession::query()->where('user_id', $user->id)->where('date', $session->date->toDateString())->first();
            $brought = $restored !== null && match ($kind) {
                PlanOverrideKind::BudgetDrop => $restored->prescription_budget === null && ($restored->prescribed_hard_minutes ?? 0) > 0,
                PlanOverrideKind::HardDayDrop => ($restored->prescribed_hard_minutes ?? 0) > 0,
                default => QualityOverride::isWorkout($restored->session_type),
            };
            if (! $brought) {
                throw self::refusal("this day can't be overridden.");
            }
        });

        return $kind;
    }

    public function undo(User $user, PlannedSession $session, Carbon $today): void
    {
        $kind = $this->periodizer->withRegenerationLock($user, fn (): PlanOverrideKind => DB::transaction(function () use ($user, $session, $today): PlanOverrideKind {
            $week = $this->lockedWeek($user, $session);
            $kind = $week?->override_kind;
            if (! $week instanceof PlanAdaptation || ! $kind instanceof PlanOverrideKind || $week->override_date?->isSameDay($session->date) !== true
                || $session->date->lt($today) || $this->sessionMatcher->activityByDate($user, $session->date, $session->date) !== []) {
                throw self::refusal("this day can't be eased again.");
            }
            $week->update(['override_kind' => null, 'override_date' => null, 'overridden_at' => null]);

            if ($kind !== PlanOverrideKind::ReadinessEase) {
                $this->periodizer->regenerateWithinLock($user, $today);
            }

            return $kind;
        }), Periodizer::REQUEST_LOCK_WAIT_SECONDS);

        if ($kind === PlanOverrideKind::ReadinessEase) {
            $this->restClampRecorder->record($user, $today, notify: false);
        }
    }

    private function lockedWeek(User $user, PlannedSession $session): ?PlanAdaptation
    {
        return PlanAdaptation::query()
            ->where('user_id', $user->id)
            ->where('week_start', $session->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
            ->lockForUpdate()
            ->first();
    }

    private static function refusal(string $message): ValidationException
    {
        return ValidationException::withMessages(['override' => $message]);
    }
}
