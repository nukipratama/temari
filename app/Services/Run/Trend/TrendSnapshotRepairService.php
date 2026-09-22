<?php

declare(strict_types=1);

namespace App\Services\Run\Trend;

use App\Events\TrendSnapshotsSettled;
use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class TrendSnapshotRepairService
{
    public const int CHUNK_DAYS = 365;

    public function __construct(private TrendSnapshotWriter $writer)
    {
    }

    public function markDirty(int $userId, Carbon $from): void
    {
        $from = $from->copy()->startOfDay();
        if ($from->gt(Carbon::today())) {
            return;
        }

        $knownUser = DB::transaction(function () use ($userId, $from): bool {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null) {
                return false;
            }

            $pending = $user->trend_snapshots_pending_from;
            if ($pending !== null && $pending->lte($from)) {
                return true;
            }

            $user->forceFill(['trend_snapshots_pending_from' => $from])->saveQuietly();
            return true;
        });

        if ($knownUser) {
            RebuildTrendSnapshotsJob::dispatch($userId)->afterCommit();
        }
    }

    public function drain(int $userId): void
    {
        $from = DB::transaction(function () use ($userId): ?Carbon {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null) {
                return null;
            }

            if ($user->trend_snapshots_rebuilding_from !== null) {
                return $user->trend_snapshots_rebuilding_from->copy();
            }

            if ($user->trend_snapshots_pending_from === null) {
                return null;
            }

            $from = $user->trend_snapshots_pending_from->copy();
            $user->forceFill([
                'trend_snapshots_pending_from' => null,
                'trend_snapshots_rebuilding_from' => $from,
            ])->saveQuietly();

            return $from;
        });

        if ($from === null) {
            return;
        }

        $today = Carbon::today();
        $through = $from->copy()->addDays(self::CHUNK_DAYS - 1);
        if ($through->gt($today)) {
            $through = $today;
        }

        if ($from->lte($through)) {
            $user = User::query()->notDemo()->find($userId);
            if ($user === null) {
                return;
            }

            $this->writer->writeRange($user, $from, $through);
        }

        $next = $through->copy()->addDay();
        $continue = false;
        $settled = false;

        DB::transaction(function () use ($userId, $next, $today, &$continue, &$settled): void {
            $user = User::query()->notDemo()->lockForUpdate()->find($userId);
            if ($user === null) {
                return;
            }

            $pending = $user->trend_snapshots_pending_from;
            if ($pending !== null) {
                $user->forceFill([
                    'trend_snapshots_pending_from' => null,
                    'trend_snapshots_rebuilding_from' => $pending->lt($next) ? $pending : $next,
                ])->saveQuietly();
                $continue = true;

                return;
            }

            if ($next->lte($today)) {
                $user->forceFill(['trend_snapshots_rebuilding_from' => $next])->saveQuietly();
                $continue = true;

                return;
            }

            $user->forceFill(['trend_snapshots_rebuilding_from' => null])->saveQuietly();
            $settled = true;
        });

        if ($continue) {
            RebuildTrendSnapshotsJob::dispatch($userId);
        } elseif ($settled) {
            TrendSnapshotsSettled::dispatch($userId);
        }
    }
}
