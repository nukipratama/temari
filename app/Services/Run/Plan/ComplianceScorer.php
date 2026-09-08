<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Turns {@see PlannedSession} rows into the verdict each one needs written
 * back. Shared by the daily `plan:score-compliance` pass, which judges every
 * past-due row at once, and by ingest, which judges the single day a run
 * just landed on.
 *
 * The trailing-week context is why this is one class rather than two copies:
 * scoring a lone week in isolation would let
 * {@see PlanRenderer::weekPhasesAndMultipliers()} read it as an isolated
 * week 1 and silently drop whatever Build ramp it is actually deep into, so
 * the prescribed km every verdict is measured against would be wrong.
 */
final readonly class ComplianceScorer
{
    /** Trailing weeks fetched around the rows being judged, so the phase ramp is visible. */
    public const int HISTORY_WEEKS = 3;

    public function __construct(
        private SessionMatcher $sessionMatcher,
        private TrainingBaseline $baseline,
    ) {
    }

    /**
     * Each day is measured against the baseline **as it stood that day**, not
     * against today's. A verdict is a historical judgment, so it has to be
     * the same figure whether it is reached the morning after or three days
     * later when a delayed run finally syncs.
     *
     * @param  Collection<int, PlannedSession>  $rows  the rows to judge
     * @return array<string, array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool, prescribed_km: float|null}>  Y-m-d => verdict
     */
    public function verdictsFor(User $user, Collection $rows, Carbon $today): array
    {
        $first = $rows->first();
        if ($first === null) {
            return [];
        }

        $rangeStart = $first->date->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(self::HISTORY_WEEKS);
        // A run's own local date can read a calendar day ahead of the server's,
        // for an athlete east of the app's timezone just after their midnight.
        $rangeEnd = $rows->pluck('date')->max();
        if ($rangeEnd === null || $rangeEnd->lessThan($today)) {
            $rangeEnd = $today;
        }

        $contextRows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->get();

        $longRunKmByDate = [];
        $kmByBaseline = [];
        $plannedKmByDate = [];
        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            $longRunKm = $longRunKmByDate[$date] ??= (float) $this->baseline->forUser($user, $row->date)['long_run_km'];
            $byDate = $kmByBaseline[(string) $longRunKm] ??= PlanRenderer::plannedKmByDate($contextRows, $longRunKm);
            if (array_key_exists($date, $byDate)) {
                // The eased distance wins where one was recorded: an athlete
                // told at 00:01 to run 3.6 instead of the 5.9 on the board is
                // graded on what they were told, not on the session it
                // replaced. Only the scorer substitutes it — PlanRenderer keeps
                // the stored figure, so the week's headline km and the day
                // cells still agree at the un-eased total.
                $plannedKmByDate[$date] = $row->clamped_km ?? $byDate[$date];
            }
        }

        $excusedByDate = $rows->mapWithKeys(
            static fn (PlannedSession $session): array => [$session->date->toDateString() => $session->isExcused()],
        )->all();

        $verdicts = $this->sessionMatcher->scoreRange($user, $plannedKmByDate, $excusedByDate, $today);
        foreach ($verdicts as $date => $verdict) {
            $verdicts[$date]['prescribed_km'] = $plannedKmByDate[$date] ?? null;
        }

        return $verdicts;
    }

    /**
     * Writes one day's verdict the moment a run lands on it, but only ever
     * upward: a day the athlete has already earned is recorded, and anything
     * short of credited is left `Planned` for the daily pass to settle once
     * the day is actually over. Falling short is not decidable while the day
     * still has hours in it; clearing the bar cannot be undone by them.
     *
     * That asymmetry is also what corrects a late arrival. A run that syncs
     * after the daily pass has already written `missed` finds a row that is
     * no longer `Planned`, which nothing else would ever revisit, and lifts
     * it — while a second run on an already-credited day can raise `done` to
     * `overreached` but never the reverse.
     */
    public function creditIfEarned(User $user, Carbon $date, Carbon $today): void
    {
        $row = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $date->toDateString())
            ->first();
        if ($row === null) {
            return;
        }

        $verdict = $this->verdictsFor($user, Collection::wrap([$row]), $today)[$date->toDateString()] ?? null;
        if ($verdict === null || ! $verdict['status']->isCredited()) {
            return;
        }
        if ($verdict['score'] !== null && $row->compliance_score !== null && $verdict['score'] <= $row->compliance_score) {
            return;
        }

        self::applyVerdict($row, $verdict);
    }

    /**
     * Records the denominator beside the verdict. Prescribed km is otherwise
     * recomputed from the athlete's *current* baseline on every render, so a
     * past day would drift away from the figure it was actually judged
     * against as fitness moved.
     *
     * @param  array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool, prescribed_km?: float|null}  $verdict
     */
    public static function applyVerdict(PlannedSession $row, array $verdict): void
    {
        $row->update([
            'status' => $verdict['status'],
            'compliance_score' => $verdict['score'],
            'prescribed_km' => $verdict['prescribed_km'] ?? null,
            'ran_anyway' => $verdict['ran_anyway'],
        ]);
    }
}
