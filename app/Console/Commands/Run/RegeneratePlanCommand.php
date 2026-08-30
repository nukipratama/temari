<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Weekly regeneration for every user's periodized plan (see
 * `routes/console.php`). The regenerate itself is deterministic and free —
 * it runs for every user, demo included, same as before. Requesting fresh
 * day/week/season plan narration for the week it just wrote is real LLM
 * cost, though, so that part is skipped for the demo user (`notDemo()`) —
 * see `docs/features/plan-periodizer.md`.
 */
#[Signature('plan:regenerate {--user= : Limit to one user id}')]
#[Description('Regenerate every user\'s periodized plan today-forward')]
class RegeneratePlanCommand extends Command
{
    public function handle(Periodizer $periodizer, PlanNarrationRequester $narrationRequester): int
    {
        $userId = $this->option('user');
        $today = Carbon::today();

        $users = User::query()
            ->when($userId !== null, fn ($query) => $query->where('id', (int) $userId))
            ->cursor();

        $count = 0;
        foreach ($users as $user) {
            $periodizer->regenerate($user);

            if ($user->is_demo === false) {
                $narrationRequester->requestForCurrentWeek($user, $today);
            }

            $count++;
        }

        $this->info("Regenerated the plan for {$count} user(s).");

        return self::SUCCESS;
    }
}
