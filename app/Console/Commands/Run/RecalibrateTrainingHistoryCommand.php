<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('plan:recalibrate-history {--user= : Limit to one user id} {--dry-run : Roll back every recalibration write}')]
#[Description('Recompute stored run metrics and training plans under the current HR zones and intensity policy')]
final class RecalibrateTrainingHistoryCommand extends Command
{
    public function handle(PlanRecalibrationService $recalibration): int
    {
        $userId = $this->option('user');
        $dryRun = (bool) $this->option('dry-run');
        $users = User::query()
            ->notDemo()
            ->when($userId !== null, fn ($query) => $query->whereKey((int) $userId))
            ->orderBy('id')
            ->cursor();

        $completed = 0;
        $failed = 0;
        foreach ($users as $user) {
            try {
                $result = $recalibration->recalibrate($user, $dryRun);
                $completed++;
                $this->line(sprintf(
                    'user %d: %d activities, %d snapshots, %d sessions, %d stale narrations%s',
                    $user->id,
                    $result['activities'],
                    $result['snapshots'],
                    $result['sessions'],
                    $result['stale_narrations'],
                    $dryRun ? ' (dry run)' : '',
                ));
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error("user {$user->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Recalibrated {$completed} non-demo user(s).".($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
