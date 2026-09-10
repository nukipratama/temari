<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\RiegelProjector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The database side of a regeneration: the athlete's season, active race,
 * stated preferences, behavioral baseline, the days they have fixed or
 * already run, and how long the race projects to take them. Everything
 * {@see Periodizer} reads, gathered in one place so
 * {@see Periodizer::rowsFor()} can stay pure.
 */
final readonly class PlanInputsGatherer
{
    public function __construct(
        private TrainingBaseline $baseline,
        private SeasonService $seasonService,
        private PlanAdapter $planAdapter,
        private RiegelProjector $riegelProjector,
        private ResolveActiveRaceAction $activeRace,
        private ResolveTrainingPreferenceAction $trainingPreference,
    ) {
    }

    public function forUser(User $user, Carbon $today): PlanInputs
    {
        $today = $today->copy()->startOfDay();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $horizonEnd = $currentWeekStart->copy()->addWeeks(Periodizer::HORIZON_WEEKS - 1)->addDays(6);

        // Keeps the season in lockstep with the plan's own mode: a race
        // set/cleared since the last call, or a self-scaled season's 12-week
        // expiry, both take effect here — see SeasonService's own docblock.
        $season = $this->seasonService->ensureCurrent($user, $today);

        $race = ($this->activeRace)($user->id);
        $preference = ($this->trainingPreference)($user->id);
        ['pinned' => $pinnedDates, 'settled' => $settledDates] = $this->pinnedAndSettledDatesIn($user, $today, $horizonEnd);

        return new PlanInputs(
            userId: $user->id,
            today: $today,
            seasonStart: $season->starts_at,
            seasonEnd: $season->ends_at,
            seasonOpensWithRecovery: $season->opens_with_recovery,
            raceDate: $race?->race_date,
            raceDistanceM: $race === null ? null : (float) $race->distance_m,
            sessionsPerWeek: $this->baseline->forUser($user, $today)['sessions_per_week'],
            runDays: $preference?->run_days,
            longRunDay: $preference?->long_run_day,
            adaptation: $this->planAdapter->forWeek($user, $currentWeekStart, $today, $race),
            pinnedDates: $pinnedDates,
            // A day that already carries a verdict is the record of what was
            // run, not a slot left to plan. Since compliance lands at ingest
            // rather than at 00:03 the next morning, regeneration can meet a
            // settled row inside its own today-forward window — see
            // `docs/decisions/a-day-is-scored-when-it-is-run.md`.
            settledDates: $settledDates,
            // How long the race will take this athlete, not how far it is: the
            // same 10K is a VO2max event for one runner and a threshold event
            // for another, and only the projection can tell them apart.
            projectedRaceSeconds: $race === null
                ? null
                : $this->riegelProjector->project($user, (float) $race->distance_m)['predicted_sec'] ?? null,
        );
    }

    /**
     * Pinned and settled are separate reads on the same window filtered by
     * different columns, but every row either query would return also
     * satisfies `pinned OR status != Planned` — so one query fetching both
     * columns, partitioned client-side, returns the identical two sets.
     *
     * @return array{pinned: array<string, true>, settled: array<string, true>}
     */
    private function pinnedAndSettledDatesIn(User $user, Carbon $from, Carbon $to): array
    {
        $rows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(fn (Builder $query): Builder => $query
                ->where('pinned', true)
                ->orWhere('status', '!=', PlannedSessionStatus::Planned))
            ->get(['date', 'pinned', 'status']);

        $pinned = [];
        $settled = [];
        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            if ($row->pinned) {
                $pinned[$date] = true;
            }
            if ($row->status !== PlannedSessionStatus::Planned) {
                $settled[$date] = true;
            }
        }

        return ['pinned' => $pinned, 'settled' => $settled];
    }
}
