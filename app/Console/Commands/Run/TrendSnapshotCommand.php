<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Jobs\Run\ReconcileScheduledTrendSnapshotsJob;
use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trend:snapshot-daily {--days= : Reconcile only this many closed days synchronously}')]
#[Description('Reconcile closed VDOT/pace-consistency snapshot rows for every user')]
class TrendSnapshotCommand extends Command
{
    public function handle(TrendSnapshotWriter $writer): int
    {
        if ($this->option('days') === null) {
            $userIds = User::query()->pluck('id');
            foreach ($userIds as $userId) {
                ReconcileScheduledTrendSnapshotsJob::dispatch((int) $userId)->afterCommit();
            }

            $this->info("Queued durable trend snapshot recovery for {$userIds->count()} users.");

            return self::SUCCESS;
        }

        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $today = now()->startOfDay();
        $from = $today->copy()->subDays($days);
        $through = $today->copy()->subDay();
        $users = User::query()->get();

        foreach ($users as $user) {
            $writer->writeRange($user, $from, $through);
        }

        $this->info("Reconciled {$days} closed trend snapshot days for {$users->count()} users.");

        return self::SUCCESS;
    }
}
