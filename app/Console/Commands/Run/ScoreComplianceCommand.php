<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily compliance pass (see `routes/console.php`): every user's
 * still-`Planned` {@see PlannedSession} rows that are now past get judged
 * and written back — `status`, `compliance_score`, `ran_anyway` — via
 * {@see SessionMatcher::scoreRange()}. Idempotent by construction: a row is
 * only ever selected while it's still `Planned`, so a same-day re-run (or a
 * user with no unscored rows) touches nothing. `--user`/`--limit` mirror
 * `plan:regenerate`'s own options.
 *
 * A user's own trailing `HISTORY_WEEKS` are fetched around their unscored
 * dates, matching `PlanController`/`CurrentWeekPlanBuilder`'s own window —
 * scoring a lone unscored week in isolation (no trailing context) would let
 * {@see PlanRenderer::weekPhasesAndMultipliers()} see it as an isolated
 * week-1 and silently drop whatever Build ramp it's actually deep into.
 */
#[Signature('plan:score-compliance {--user= : Limit to one user id} {--limit=500 : Max users processed per run}')]
#[Description("Score every user's past-due Planned sessions and persist the verdict")]
class ScoreComplianceCommand extends Command
{
    private const int HISTORY_WEEKS = 3;

    public function handle(SessionMatcher $sessionMatcher, TrainingBaseline $baseline): int
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
            $scored += $this->scoreUser($sessionMatcher, $baseline, $user, $today);
        }

        $this->info(sprintf('Scored %d planned session(s) across %d user(s).', $scored, $userIds->count()));

        return self::SUCCESS;
    }

    private function scoreUser(SessionMatcher $sessionMatcher, TrainingBaseline $baseline, User $user, Carbon $today): int
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

        $earliestStaleWeekStart = $staleRows->first()->date->copy()->startOfWeek(Carbon::MONDAY);
        $rangeStart = $earliestStaleWeekStart->copy()->subWeeks(self::HISTORY_WEEKS);

        $contextRows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $today->copy()->subDay()->toDateString()])
            ->orderBy('date')
            ->get();

        $longRunKm = $baseline->forUser($user, $today)['long_run_km'];
        $plannedKmByDate = PlanRenderer::plannedKmByDate($contextRows, $longRunKm);
        $skippedByDate = $staleRows->mapWithKeys(
            fn (PlannedSession $s): array => [$s->date->toDateString() => $s->skipped],
        )->all();
        $stalePlannedKm = array_intersect_key($plannedKmByDate, $skippedByDate);

        $results = $sessionMatcher->scoreRange($user, $stalePlannedKm, $skippedByDate, $today);

        foreach ($staleRows as $row) {
            $result = $results[$row->date->toDateString()] ?? null;
            if ($result === null) {
                continue;
            }
            $row->update([
                'status' => $result['status'],
                'compliance_score' => $result['score'],
                'ran_anyway' => $result['ran_anyway'],
            ]);
        }

        return $staleRows->count();
    }
}
