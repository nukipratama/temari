<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

#[Signature('ai:weekly-recap')]
#[Description('Kick off the connected weekly-recap chain: narrate every completed week whose recap is not Done, oldest first')]
class WeeklyRecapCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();
        $oldestReal = Carbon::now()->subDays((int) config('ai.backfill_max_age_days'))->toDateString();

        // Every completed week (week_ending <= the latest fully-closed week,
        // runs > 0) whose WeeklyRecap is not yet Done — Pending, Failed, or
        // never created.
        $baseQuery = fn (): Builder => WeeklySnapshot::query()
            ->where('week_ending', '<=', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done));

        // Weeks older than the backfill depth cap never get a real LLM call —
        // rule-based fill instead, same as the per-activity cap.
        $tooOld = $baseQuery()->where('week_ending', '<', $oldestReal)->get();
        $tooOld->each(fn (WeeklySnapshot $snapshot) => $service->requestRuleBased(
            subjectOrType: WeeklySnapshot::class,
            subjectId: (int) $snapshot->id,
            type: AnalysisType::WeeklyRecap,
        ));

        // Ordered oldest first so the connected story narrates in chronological
        // order: the kickoff dispatches the earliest link and the job chain
        // (AnalyzeWeeklyRecapJob) walks forward to each successor once its
        // predecessor is Done. invalidate:false never re-bills a Done recap,
        // so this doubles as a daily resume safety net for stalled links.
        $snapshots = $baseQuery()->where('week_ending', '>=', $oldestReal)->orderBy('week_ending')->get();

        $stagger = (int) config('ai.backfill_stagger_seconds', 360);

        $snapshots->each(function (WeeklySnapshot $snapshot, int $index) use ($service, $stagger): void {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                delaySeconds: $index * $stagger,
                invalidate: false,
            );
        });

        $this->info("Dispatched weekly recap for {$snapshots->count()} snapshots ({$tooOld->count()} filled rule-based) through week ending {$lastWeekEnding}.");

        return self::SUCCESS;
    }
}
