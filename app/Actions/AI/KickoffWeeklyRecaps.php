<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\HydrationBacklog;
use App\Services\AI\RecapHydrationReadiness;
use App\Services\AI\RecapPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Kicks off the connected weekly-recap chain for every completed week whose
 * recap is not Done — the scheduled Monday sweep and the one-shot kickoff that
 * follows a first-connect backfill draw from this single query.
 *
 * A week the ingest pipeline is still hydrating is held back by
 * {@see RecapHydrationReadiness} rather than narrated thin — for every branch
 * below, rule-based included, since a rule-based fill is Done immediately and
 * `invalidate: false` means it is never revisited once written. A week that
 * closed before the athlete connected Strava is filled rule-based up front,
 * the same as a week past the backfill depth cap — Temari was not there for it.
 *
 * @see docs/decisions/deferred-recap-windowing.md
 */
class KickoffWeeklyRecaps
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly BackfillAgeGate $ages,
        private readonly RecapHydrationReadiness $readiness,
        private readonly RecentlyActiveUsers $activeUsers,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    /**
     * @param  int|null  $userId  narrow to one user; null sweeps every active athlete
     * @return array{dispatched: int, rule_based: int, deferred: int}
     */
    public function __invoke(?int $userId = null): array
    {
        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();
        $oldestReal = $this->ages->cutoffDate();

        // Every completed week (week_ending <= the latest fully-closed week,
        // runs > 0) whose WeeklyRecap is not yet Done — Pending, Failed, or
        // never created.
        $baseQuery = fn (): Builder => WeeklySnapshot::query()
            ->where('week_ending', '<=', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->whereIn('user_id', $this->activeUsers->query()->select('id'))
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', $userId))
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done));

        // Weeks older than the backfill depth cap never get a real LLM call —
        // rule-based fill instead, same as the per-activity cap. A rule-based
        // fill reads the same snapshot columns (form_status, atl/ctl) the LLM
        // path does, so it races the same ordering problem and is held to the
        // same hydration gate rather than reading them half-filled.
        $tooOldCandidates = $baseQuery()->where('week_ending', '<', $oldestReal)->get();
        $tooOld = $this->readiness->ready($tooOldCandidates);
        $tooOld->each(fn (WeeklySnapshot $snapshot) => $this->service->requestRuleBased(
            subjectOrType: WeeklySnapshot::class,
            subjectId: (int) $snapshot->id,
            type: AnalysisType::WeeklyRecap,
        ));

        // Ordered oldest first so the connected story narrates in chronological
        // order: the kickoff dispatches the earliest link and the job chain
        // (AnalyzeWeeklyRecapJob) walks forward to each successor once its
        // predecessor is Done. invalidate:false never re-bills a Done recap,
        // so this doubles as a daily resume safety net for stalled links.
        $candidates = $baseQuery()->where('week_ending', '>=', $oldestReal)->orderBy('week_ending')->get();

        // A week that closed before the athlete connected Strava is history
        // Temari never watched — filled rule-based, same as a week too old.
        /** @var list<int> $userIds */
        $userIds = $candidates->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $connectedAt = $this->backlog->connectedAtFor($userIds);
        $preConnectCandidates = $candidates->filter(fn (WeeklySnapshot $snapshot): bool => $this->closedBeforeConnect(
            $snapshot->week_ending,
            $connectedAt[(int) $snapshot->user_id] ?? null,
        ));
        $eligible = $candidates->reject(fn (WeeklySnapshot $snapshot): bool => $this->closedBeforeConnect(
            $snapshot->week_ending,
            $connectedAt[(int) $snapshot->user_id] ?? null,
        ));

        $preConnect = $this->readiness->ready($preConnectCandidates);
        $preConnect->each(fn (WeeklySnapshot $snapshot) => $this->service->requestRuleBased(
            subjectOrType: WeeklySnapshot::class,
            subjectId: (int) $snapshot->id,
            type: AnalysisType::WeeklyRecap,
        ));

        $snapshots = $this->readiness->ready($eligible);

        $stagger = (int) config('ai.backfill_stagger_seconds', 360);

        $snapshots->each(function (WeeklySnapshot $snapshot, int $index) use ($stagger): void {
            $this->service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                delaySeconds: $index * $stagger,
                invalidate: false,
            );
        });

        $deferred = ($tooOldCandidates->count() - $tooOld->count())
            + ($preConnectCandidates->count() - $preConnect->count())
            + ($eligible->count() - $snapshots->count());

        return [
            'dispatched' => $snapshots->count(),
            'rule_based' => $tooOld->count() + $preConnect->count(),
            'deferred' => $deferred,
        ];
    }

    /**
     * Whether the week ending on $weekEnding was already over before the
     * athlete's Strava connection landed — the same anchor
     * {@see RecapHydrationReadiness} and {@see \App\Services\AI\HistoryNarrationGate} use.
     */
    private function closedBeforeConnect(Carbon $weekEnding, ?Carbon $connectedAt): bool
    {
        return $connectedAt !== null && $weekEnding->copy()->endOfDay()->lt($connectedAt);
    }
}
