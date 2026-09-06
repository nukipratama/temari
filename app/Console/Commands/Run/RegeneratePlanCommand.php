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
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

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
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $userId = $this->option('user');
        $today = Carbon::today();

        $users = User::query()
            ->when($userId !== null, fn ($query) => $query->where('id', (int) $userId))
            ->cursor();

        $count = 0;
        $failed = 0;
        foreach ($users as $user) {
            // One athlete's data must never cost every athlete after them
            // their week. This ran as a bare loop until a race goal whose day
            // had passed made PhaseSchedule::forRace() throw, and the whole
            // command died on the first user holding one.
            try {
                $periodizer->regenerate($user);

                if ($user->is_demo === false) {
                    $narrationRequester->requestForCurrentWeek($user, $today);
                }

                $count++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
                Log::error('plan.regenerate.user_failed', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Regenerated the plan for {$count} user(s)."
            .($failed > 0 ? " {$failed} failed; see the log." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
