<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\RecapHydrationReadiness;
use App\Services\AI\RecapPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kicks off the connected weekly-recap chain for every completed week whose
 * recap is not Done — the scheduled Monday sweep and the one-shot kickoff that
 * follows a first-connect backfill draw from this single query.
 *
 * A week the ingest pipeline is still hydrating is held back by
 * {@see RecapHydrationReadiness} rather than narrated thin.
 */
class KickoffWeeklyRecaps
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly BackfillAgeGate $ages,
        private readonly RecapHydrationReadiness $readiness,
    ) {
    }

    /**
     * @param  int|null  $userId  narrow to one user; null sweeps every non-demo user
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
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', $userId))
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done));

        // Weeks older than the backfill depth cap never get a real LLM call —
        // rule-based fill instead, same as the per-activity cap.
        $tooOld = $baseQuery()->where('week_ending', '<', $oldestReal)->get();
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
        $snapshots = $this->readiness->ready($candidates);

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

        return [
            'dispatched' => $snapshots->count(),
            'rule_based' => $tooOld->count(),
            'deferred' => $candidates->count() - $snapshots->count(),
        ];
    }
}
