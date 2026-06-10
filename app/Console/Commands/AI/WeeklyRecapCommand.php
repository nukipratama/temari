<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:weekly-recap')]
#[Description('Narrate last week\'s recap (on final data) and self-heal any recent recap that never generated')]
class WeeklyRecapCommand extends Command
{
    /** How many recent weeks the self-heal sweep looks back over. */
    private const int SWEEP_WEEKS = 3;

    public function handle(AnalysisService $service): int
    {
        $lastWeekEnding = Carbon::today()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay()->toDateString();

        // Primary: the just-closed week, narrated once on final data. invalidate
        // regenerates even a stale "Baca ulang" Done row. Zero-run snapshots exist
        // for CTL continuity; nothing to narrate there.
        $primary = WeeklySnapshot::query()
            ->where('week_ending', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->whereHas('user', fn ($query) => $query->where('is_demo', false))
            ->get();

        foreach ($primary as $snapshot) {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                invalidate: true,
            );
        }

        $healed = $this->selfHeal($service, $lastWeekEnding);

        $this->info("Dispatched weekly recap for {$primary->count()} snapshots (week ending {$lastWeekEnding}); re-dispatched {$healed} stalled.");

        return self::SUCCESS;
    }

    /**
     * Re-dispatch any WeeklyRecap from the prior few weeks that never generated
     * (Pending/Failed) — e.g. a Monday run that hit a transient Azure outage. A3
     * defers weekly recap to this single Monday command, so without this a missed
     * Monday would otherwise strand that week's recap until manual "Baca ulang".
     * invalidate:false re-dispatches only Pending/Failed; it leaves Done untouched
     * (no re-bill). The just-closed week is owned by the primary pass above.
     */
    private function selfHeal(AnalysisService $service, string $lastWeekEnding): int
    {
        // Derive the window from lastWeekEnding so it covers exactly SWEEP_WEEKS
        // week-endings immediately before the just-closed week.
        $sweepFrom = Carbon::parse($lastWeekEnding)->subWeeks(self::SWEEP_WEEKS)->toDateString();

        $stalledSnapshotIds = Analysis::query()
            ->where('analysis_type', AnalysisType::WeeklyRecap)
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Failed])
            ->where('subject_type', WeeklySnapshot::class)
            ->pluck('subject_id');

        $snapshots = WeeklySnapshot::query()
            ->whereIn('id', $stalledSnapshotIds)
            ->where('week_ending', '>=', $sweepFrom)
            ->where('week_ending', '<', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->whereHas('user', fn ($query) => $query->where('is_demo', false))
            ->get();

        foreach ($snapshots as $snapshot) {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                invalidate: false,
            );
        }

        return $snapshots->count();
    }
}
