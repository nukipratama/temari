<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\User;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Weekly regeneration for every user's periodized plan (see
 * `routes/console.php`). Deterministic, no LLM cost, so unlike the AI
 * commands this runs for every user, demo included — see
 * `docs/features/plan-periodizer.md`.
 */
#[Signature('plan:regenerate {--user= : Limit to one user id}')]
#[Description('Regenerate every user\'s periodized plan today-forward')]
class RegeneratePlanCommand extends Command
{
    public function handle(Periodizer $periodizer): int
    {
        $userId = $this->option('user');

        $users = User::query()
            ->when($userId !== null, fn ($query) => $query->where('id', (int) $userId))
            ->cursor();

        $count = 0;
        foreach ($users as $user) {
            $periodizer->regenerate($user);
            $count++;
        }

        $this->info("Regenerated the plan for {$count} user(s).");

        return self::SUCCESS;
    }
}
