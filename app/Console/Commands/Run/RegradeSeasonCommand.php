<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\PlannedSession;
use App\Models\Season;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\ComplianceScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-off regrade of every past day in each athlete's current season under
 * the distance-and-intent rule. Deterministic, so re-running it writes nothing
 * to the plan rows themselves. See
 * `docs/decisions/a-day-is-graded-on-distance-and-intent.md`.
 *
 * Also backfills a rule-based "Temari's read" for every credited day the
 * regrade touches (#939) — never the LLM, and in the same deploy-time step,
 * so history phrases the verdict rather than keeping whatever ahead-of-time
 * label a pre-#939 row carried. Run once after deploy:
 * `./vendor/bin/sail artisan plan:regrade-season`.
 */
#[Signature('plan:regrade-season {--user= : Limit to one user id}')]
#[Description("Regrade every past day of each athlete's current season on distance and intent")]
class RegradeSeasonCommand extends Command
{
    public function handle(ComplianceScorer $scorer, PlanNarrationRequester $narrationRequester): int
    {
        $today = Carbon::today();
        $userOption = $this->option('user');

        $seasons = Season::query()
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            ->when($userOption !== null, fn ($query) => $query->where('user_id', (int) $userOption))
            ->with('user')
            ->orderBy('user_id')
            ->get();

        $regraded = 0;
        $read = 0;
        foreach ($seasons as $season) {
            $rows = PlannedSession::query()
                ->where('user_id', $season->user_id)
                ->whereBetween('date', [$season->starts_at->toDateString(), $today->copy()->subDay()->toDateString()])
                ->orderBy('date')
                ->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $verdicts = $scorer->verdictsFor($season->user, $rows, $today);
            foreach ($rows as $row) {
                $verdict = $verdicts[$row->date->toDateString()] ?? null;
                if ($verdict === null) {
                    continue;
                }

                ComplianceScorer::applyVerdict($row, $verdict);
                $regraded += $row->wasChanged() ? 1 : 0;

                if ($verdict['status']->isCredited()) {
                    $narrationRequester->backfillRuleBasedRead($season->user, $row->date);
                    $read++;
                }
            }
        }

        $this->info(sprintf(
            'Regraded %d planned session(s) across %d season(s), backfilling %d rule-based read(s).',
            $regraded,
            $seasons->count(),
            $read,
        ));

        return self::SUCCESS;
    }
}
