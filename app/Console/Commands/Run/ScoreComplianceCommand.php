<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\ComplianceScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily compliance pass (see `routes/console.php`): every user's
 * still-`Planned` {@see PlannedSession} rows that are now past get judged
 * and written back — `status`, `compliance_score`, `ran_anyway` — via
 * {@see ComplianceScorer}. Idempotent by construction: a row is only ever
 * selected while it's still `Planned`, so a same-day re-run (or a user with
 * no unscored rows) touches nothing. `--user`/`--limit` mirror
 * `plan:regenerate`'s own options.
 *
 * This settles the days that ended short. The days the athlete actually
 * earned are already recorded, the moment the run landed, by
 * {@see ComplianceScorer::creditIfEarned()} — which is also what stops a run
 * that syncs after this pass from being frozen out of its own day.
 */
#[Signature('plan:score-compliance {--user= : Limit to one user id} {--limit=500 : Max users processed per run}')]
#[Description("Score every user's past-due Planned sessions and persist the verdict")]
class ScoreComplianceCommand extends Command
{
    public function handle(ComplianceScorer $scorer): int
    {
        $today = Carbon::today();
        $userOption = $this->option('user');
        $limit = (int) $this->option('limit');

        $userIds = PlannedSession::query()
            ->where('status', PlannedSessionStatus::Planned)
            ->where('date', '<', $today->toDateString())
            ->when($userOption !== null, fn ($query) => $query->where('user_id', (int) $userOption))
            ->distinct()
            ->orderBy('user_id')
            ->limit($limit)
            ->pluck('user_id');

        $scored = 0;
        foreach ($userIds as $userId) {
            $user = User::query()->find((int) $userId);
            if (! $user instanceof User) {
                continue;
            }
            $scored += $this->scoreUser($scorer, $user, $today);
        }

        $this->info(sprintf('Scored %d planned session(s) across %d user(s).', $scored, $userIds->count()));

        return self::SUCCESS;
    }

    private function scoreUser(ComplianceScorer $scorer, User $user, Carbon $today): int
    {
        $staleRows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('status', PlannedSessionStatus::Planned)
            ->where('date', '<', $today->toDateString())
            ->orderBy('date')
            ->get();
        if ($staleRows->isEmpty()) {
            return 0;
        }

        $verdicts = $scorer->verdictsFor($user, $staleRows, $today);

        foreach ($staleRows as $row) {
            $verdict = $verdicts[$row->date->toDateString()] ?? null;
            if ($verdict === null) {
                continue;
            }
            $row->update([
                'status' => $verdict['status'],
                'compliance_score' => $verdict['score'],
                'ran_anyway' => $verdict['ran_anyway'],
            ]);
        }

        return $staleRows->count();
    }
}
