<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off correction for cards and moods minted mid-backfill. Owner-run, never
 * on deploy; `--dry-run` reports the same changes and rolls them back.
 */
#[Signature('run:recompute-card-claims {--user= : Limit to one user id} {--dry-run : Report the changes without keeping them}')]
#[Description('Re-judge every run card PR flag and post-run mood in date order')]
class RecomputeCardClaimsCommand extends Command
{
    public function handle(RecomputeCardClaimsAction $recompute): int
    {
        $userOption = $this->option('user');
        $dryRun = (bool) $this->option('dry-run');

        $users = User::query()
            ->where('is_demo', false)
            ->when($userOption !== null, fn ($query) => $query->whereKey((int) $userOption))
            ->get();

        foreach ($users as $user) {
            DB::beginTransaction();
            $result = $recompute($user);
            $dryRun ? DB::rollBack() : DB::commit();

            $this->line(sprintf(
                'user %d: %d PR flags cleared [%s], %d earned [%s], %d moods changed',
                $user->id,
                count($result['cleared']),
                implode(', ', $result['cleared']),
                count($result['earned']),
                implode(', ', $result['earned']),
                $result['moods'],
            ));
        }

        $this->info($dryRun ? 'Dry run: nothing was kept.' : 'Done.');

        return self::SUCCESS;
    }
}
