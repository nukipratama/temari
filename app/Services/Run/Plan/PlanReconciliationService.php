<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Jobs\Run\ReconcilePlanJob;
use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class PlanReconciliationService
{
    public function __construct(
        private Periodizer $periodizer,
        private PlanNarrationRequester $planNarration,
    ) {
    }

    public function markDirty(int $userId, Carbon $from): void
    {
        $from = $from->copy()->startOfDay();
        if (! self::dateCanAffectPlan($from, Carbon::today())) {
            return;
        }

        $knownUser = DB::transaction(function () use ($userId, $from): bool {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null) {
                return false;
            }

            $pending = $user->plan_reconciliation_pending_from;
            if ($pending !== null && $pending->lte($from)) {
                return true;
            }

            $user->forceFill(['plan_reconciliation_pending_from' => $from])->saveQuietly();

            return true;
        });

        if ($knownUser) {
            ReconcilePlanJob::dispatch($userId)->afterCommit();
        }
    }

    public function drain(int $userId): void
    {
        $from = DB::transaction(function () use ($userId): ?Carbon {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null) {
                return null;
            }

            if ($user->plan_reconciliation_rebuilding_from !== null) {
                return $user->plan_reconciliation_rebuilding_from->copy();
            }

            if ($user->plan_reconciliation_pending_from === null) {
                return null;
            }

            $from = $user->plan_reconciliation_pending_from->copy();
            $user->forceFill([
                'plan_reconciliation_pending_from' => null,
                'plan_reconciliation_rebuilding_from' => $from,
            ])->saveQuietly();

            return $from;
        });

        if ($from === null) {
            return;
        }

        $user = User::query()->notDemo()->find($userId);
        if ($user === null) {
            return;
        }

        $today = Carbon::today();
        if (self::dateCanAffectPlan($from, $today)) {
            $changed = $this->periodizer->regenerateIfChanged($user, $today);
            if ($changed) {
                $this->planNarration->requestForCurrentWeekAfterPlanChange($user, $today);
            }
        }

        $again = $this->finish($userId);

        if ($again) {
            ReconcilePlanJob::dispatch($userId);
        }
    }

    public static function dateCanAffectPlan(Carbon $from, Carbon $today): bool
    {
        $from = $from->copy()->startOfDay();
        $today = $today->copy()->startOfDay();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        return ! $from->lt($currentWeekStart->subWeek()) && ! $from->gt($today);
    }

    private function finish(int $userId): bool
    {
        return DB::transaction(function () use ($userId): bool {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null) {
                return false;
            }

            $pending = $user->plan_reconciliation_pending_from;
            if ($pending !== null) {
                $user->forceFill([
                    'plan_reconciliation_pending_from' => null,
                    'plan_reconciliation_rebuilding_from' => $pending,
                ])->saveQuietly();

                return true;
            }

            $user->forceFill(['plan_reconciliation_rebuilding_from' => null])->saveQuietly();

            return false;
        });
    }
}
