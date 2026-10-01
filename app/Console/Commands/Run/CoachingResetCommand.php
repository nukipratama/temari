<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use App\Services\Run\Plan\CoachingReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('coaching:reset {--user= : Limit to one user id} {--dry-run : Print the before and after counts and keep nothing}')]
#[Description('One-time pre-launch reset: rebuild every derived record from stored runs under the current coaching policy, re-grade history and mark narration stale')]
final class CoachingResetCommand extends Command
{
    public function handle(CoachingReset $reset, NarrationOrigin $origin): int
    {
        $origin->set(AnalysisOrigin::Recovery);
        $userId = $this->option('user');
        $dryRun = (bool) $this->option('dry-run');
        $users = User::query()
            ->notDemo()
            ->when($userId !== null, fn ($query) => $query->whereKey((int) $userId))
            ->orderBy('id')
            ->cursor();

        $done = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($users as $user) {
            try {
                $result = $reset->reset($user, $dryRun);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error("user {$user->id}: rolled back, {$exception->getMessage()}");

                continue;
            }

            if ($result['skipped']) {
                $skipped++;
                $this->line("user {$user->id}: already reset, nothing to do");

                continue;
            }

            $done++;
            $this->line("user {$user->id}".($dryRun ? ' (dry run)' : '').':');
            $this->table(
                ['', 'before', 'after'],
                array_map(
                    static fn (string $label): array => [$label, $result['before'][$label], $result['after'][$label]],
                    array_keys($result['before']),
                ),
            );
        }

        $this->info(sprintf(
            '%s %d non-demo user(s); %d already reset.%s',
            $dryRun ? 'Dry run: nothing was kept for' : 'Reset',
            $done,
            $skipped,
            $failed > 0 ? " {$failed} failed and were rolled back; rerun to retry them." : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
