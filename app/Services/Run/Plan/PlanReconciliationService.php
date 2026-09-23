<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Jobs\Run\ReconcilePlanJob;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class PlanReconciliationService
{
    public function __construct(private Periodizer $periodizer)
    {
    }

    public function markDirty(int $userId, Carbon $from): void
    {
        $from = $from->copy()->startOfDay();
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

        $this->periodizer->regenerateIfChanged($user);

        $again = DB::transaction(function () use ($userId): bool {
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

        if ($again) {
            ReconcilePlanJob::dispatch($userId);
        }
    }
}
